<?php

namespace App\Services;

use App\Models\AnnualBonusAccount;
use App\Models\AnnualBonusMovement;
use App\Models\Bonus;
use App\Models\OrdinaryAccount;
use App\Models\OrdinaryAccountTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnnualBonusService
{
    /**
     * Capitalise un bonus dans le compte bonus annuel de l'utilisateur.
     * Le bonus n'est PAS versé immédiatement dans le compte ordinaire.
     * Sera distribué à la fin de l'année.
     */
    public function accrueBonus(Bonus $bonus, int $year = 0): AnnualBonusAccount
    {
        if ($year === 0) {
            $year = (int) date('Y');
        }

        return DB::transaction(function () use ($bonus, $year) {
            $userId = $bonus->user_id;

            // Trouver ou créer le compte bonus annuel de l'utilisateur pour cette année
            $bonusAccount = AnnualBonusAccount::firstOrCreate(
                ['user_id' => $userId, 'fiscal_year' => $year],
                [
                    'gross_bonus_total' => 0,
                    'malus_total'       => 0,
                    'net_bonus_balance' => 0,
                    'status'            => 'pending',
                ]
            );

            // Enregistrer le mouvement
            AnnualBonusMovement::create([
                'annual_bonus_account_id' => $bonusAccount->id,
                'bonus_id'                => $bonus->id,
                'movement_type'           => 'bonus_credit',
                'amount'                  => $bonus->amount,
                'description'             => "Bonus capitalisé – {$bonus->type} du " . now()->toDateString(),
            ]);

            // Mettre à jour les totaux
            $bonusAccount->gross_bonus_total += $bonus->amount;
            $bonusAccount->net_bonus_balance = round(
                $bonusAccount->gross_bonus_total - $bonusAccount->malus_total,
                2
            );
            $bonusAccount->save();

            // Marquer le bonus comme tenu pour versement annuel
            $bonus->held_for_annual_payout = true;
            $bonus->annual_bonus_account_id = $bonusAccount->id;
            $bonus->save();

            return $bonusAccount;
        });
    }

    /**
     * Capitalise un malus (réduit le solde bonus de l'utilisateur).
     */
    public function accrueMalus(int $userId, float $malusAmount, ?string $description = null, int $year = 0): AnnualBonusAccount
    {
        if ($year === 0) {
            $year = (int) date('Y');
        }

        return DB::transaction(function () use ($userId, $malusAmount, $description, $year) {
            $bonusAccount = AnnualBonusAccount::firstOrCreate(
                ['user_id' => $userId, 'fiscal_year' => $year],
                [
                    'gross_bonus_total' => 0,
                    'malus_total'       => 0,
                    'net_bonus_balance' => 0,
                    'status'            => 'pending',
                ]
            );

            AnnualBonusMovement::create([
                'annual_bonus_account_id' => $bonusAccount->id,
                'bonus_id'                => null,
                'movement_type'           => 'malus_debit',
                'amount'                  => $malusAmount,
                'description'             => $description ?? "Malus appliqué",
            ]);

            $bonusAccount->malus_total += $malusAmount;
            $bonusAccount->net_bonus_balance = round(
                $bonusAccount->gross_bonus_total - $bonusAccount->malus_total,
                2
            );
            $bonusAccount->save();

            return $bonusAccount;
        });
    }

    /**
     * Exécute le versement de fin d'année pour tous les membres ayant un solde bonus positif.
     * À appeler le 31 décembre (Artisan Command schedulé).
     */
    public function processYearEndDisbursement(int $year = 0, ?int $operatorId = null): array
    {
        if ($year === 0) {
            $year = (int) date('Y');
        }

        $results = [
            'year'       => $year,
            'disbursed'  => [],
            'skipped'    => [],
            'errors'     => [],
        ];

        $pendingAccounts = AnnualBonusAccount::where('fiscal_year', $year)
            ->where('status', 'pending')
            ->where('net_bonus_balance', '>', 0)
            ->get();

        foreach ($pendingAccounts as $bonusAccount) {
            try {
                DB::transaction(function () use ($bonusAccount, $operatorId, $year, &$results) {
                    $userId = $bonusAccount->user_id;
                    $netBonus = (float) $bonusAccount->net_bonus_balance;

                    if ($netBonus <= 0) {
                        $results['skipped'][] = "User #{$userId}: solde net bonus ≤ 0 ({$netBonus})";
                        return;
                    }

                    $oa = OrdinaryAccount::where('user_id', $userId)->first();
                    if (!$oa) {
                        $results['skipped'][] = "User #{$userId}: pas de compte ordinaire";
                        return;
                    }

                    $balanceBefore = (float) $oa->balance;
                    $balanceAfter  = round($balanceBefore + $netBonus, 2);

                    $oa->balance = $balanceAfter;
                    if ($balanceAfter >= 0 && $oa->negative_since !== null) {
                        $oa->negative_since = null;
                    }
                    $oa->save();

                    $reference = 'BONUS-' . $year . '-' . strtoupper(Str::random(6));

                    OrdinaryAccountTransaction::create([
                        'ordinary_account_id' => $oa->id,
                        'transaction_type'    => 'annual_bonus_disbursement',
                        'direction'           => 'credit',
                        'amount'              => $netBonus,
                        'balance_before'      => $balanceBefore,
                        'balance_after'       => $balanceAfter,
                        'description'         => "Versement bonus annuel {$year} – Net bonus capitalisé",
                        'reference'           => $reference,
                        'payment_method'      => 'account_credit',
                        'operator_id'         => $operatorId,
                        'operation_nature'    => 'annual_bonus_disbursement',
                    ]);

                    // Enregistrer le mouvement de versement
                    AnnualBonusMovement::create([
                        'annual_bonus_account_id' => $bonusAccount->id,
                        'bonus_id'                => null,
                        'movement_type'           => 'disbursement',
                        'amount'                  => $netBonus,
                        'description'             => "Versement fin d'année {$year} vers compte ordinaire",
                    ]);

                    // Clôturer le compte bonus
                    $bonusAccount->status          = 'disbursed';
                    $bonusAccount->disbursed_at    = Carbon::now();
                    $bonusAccount->disbursed_amount = $netBonus;
                    $bonusAccount->save();

                    $results['disbursed'][] = [
                        'user_id'    => $userId,
                        'amount'     => $netBonus,
                        'reference'  => $reference,
                    ];
                });
            } catch (\Exception $e) {
                $results['errors'][] = "User #{$bonusAccount->user_id}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Retourne le résumé du compte bonus annuel d'un utilisateur.
     */
    public function getUserBonusAccount(int $userId, int $year = 0): ?array
    {
        if ($year === 0) {
            $year = (int) date('Y');
        }

        $account = AnnualBonusAccount::where('user_id', $userId)
            ->where('fiscal_year', $year)
            ->with('movements')
            ->first();

        if (!$account) {
            return [
                'user_id'            => $userId,
                'fiscal_year'        => $year,
                'gross_bonus_total'  => 0,
                'malus_total'        => 0,
                'net_bonus_balance'  => 0,
                'status'             => 'no_account',
                'disbursed_at'       => null,
                'disbursed_amount'   => null,
                'movements'          => [],
            ];
        }

        return [
            'id'                 => $account->id,
            'user_id'            => $account->user_id,
            'fiscal_year'        => $account->fiscal_year,
            'gross_bonus_total'  => $account->gross_bonus_total,
            'malus_total'        => $account->malus_total,
            'net_bonus_balance'  => $account->net_bonus_balance,
            'status'             => $account->status,
            'disbursed_at'       => $account->disbursed_at,
            'disbursed_amount'   => $account->disbursed_amount,
            'movements'          => $account->movements->map(fn($m) => [
                'type'        => $m->movement_type,
                'amount'      => $m->amount,
                'description' => $m->description,
                'created_at'  => $m->created_at,
            ])->values()->toArray(),
        ];
    }

    /**
     * Liste de tous les comptes bonus annuels (vue admin).
     */
    public function getAllBonusAccounts(int $year = 0): array
    {
        if ($year === 0) {
            $year = (int) date('Y');
        }

        return AnnualBonusAccount::where('fiscal_year', $year)
            ->orderByDesc('net_bonus_balance')
            ->get()
            ->map(function ($acc) {
                return [
                    'id'                => $acc->id,
                    'user_id'           => $acc->user_id,
                    'fiscal_year'       => $acc->fiscal_year,
                    'gross_bonus_total' => $acc->gross_bonus_total,
                    'malus_total'       => $acc->malus_total,
                    'net_bonus_balance' => $acc->net_bonus_balance,
                    'status'            => $acc->status,
                    'disbursed_at'      => $acc->disbursed_at,
                    'disbursed_amount'  => $acc->disbursed_amount,
                ];
            })
            ->toArray();
    }
}
