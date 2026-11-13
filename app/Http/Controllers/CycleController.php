<?php

namespace App\Http\Controllers;

use App\Models\Bonus;
use App\Models\Branch;
use App\Models\Cycle;
use App\Models\Deposit;
use App\Models\Individual;
use App\Models\Malus;
use App\Models\Organisation;
use App\Models\OrdinaryAccount;
use App\Models\OrdinaryAccountTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CycleController extends Controller
{
    /** GET /api/cycles — liste tous les cycles (actif en premier) */
    public function index(Request $request): JsonResponse
    {
        $query = Cycle::with([
            'branch:id,name',
            'openedBy:id,name',
            'closedBy:id,name',
            'ordinaryAccount',
            'user:id,name,phone',
            'individual.user:id,name,phone',
            'organisation:id,name,phone',
        ])
            ->orderByRaw("FIELD(status,'active','planned','closed')")
            ->orderByDesc('created_at');

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhereHas('branch', fn($b) => $b->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                  ->orWhereHas('individual.user', fn($iu) => $iu->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        return response()->json($query->get());
    }

    /** GET /api/cycles/check-entity-eligibility — Vérifier si une entité a un compte ordinaire approvisionné */
    public function checkEntityEligibility(Request $request): JsonResponse
    {
        $request->validate([
            'entity_type' => 'required|string|in:individual,organisation,branch',
            'entity_id'   => 'required|integer',
        ]);

        $entityType = $request->input('entity_type');
        $entityId   = (int) $request->input('entity_id');

        $userId = null;
        $entityName = '';
        if ($entityType === 'individual') {
            $ind = Individual::with('user')->find($entityId);
            if (!$ind) {
                return response()->json(['error' => 'Entrepreneur introuvable.'], 404);
            }
            $userId = $ind->user_id;
            $entityName = $ind->user?->name ?? "Entrepreneur #{$entityId}";
        } elseif ($entityType === 'organisation') {
            $org = Organisation::find($entityId);
            if (!$org) {
                return response()->json(['error' => 'Organisation introuvable.'], 404);
            }
            $userId = $org->created_by ?? null;
            $entityName = $org->name;
        } elseif ($entityType === 'branch') {
            $branch = Branch::find($entityId);
            if (!$branch) {
                return response()->json(['error' => 'Succursale introuvable.'], 404);
            }
            $userId = $branch->manager_user_id ?? null;
            $entityName = "Succursale {$branch->name}";
        }

        $account = OrdinaryAccount::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->first();

        if (!$account && $userId) {
            $account = OrdinaryAccount::where('user_id', $userId)->first();
        }

        $hasAccount = (bool) $account;
        $isFunded = $account ? $account->hasBeenFunded() : false;
        $balance = $account ? (float) $account->balance : 0.0;

        $activeCycle = Cycle::where('status', 'active')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->first();

        $eligible = $hasAccount && $isFunded && !$activeCycle;

        $message = '';
        if (!$hasAccount || !$isFunded) {
            $message = "Le compte ordinaire de \"{$entityName}\" n'a jamais été approvisionné. Un versement initial sur son compte ordinaire est requis avant d'ouvrir un cycle de cotisation.";
        } elseif ($activeCycle) {
            $message = "\"{$entityName}\" possède déjà un cycle actif (#{$activeCycle->id} - {$activeCycle->name}).";
        } else {
            $message = "\"{$entityName}\" est éligible pour ouvrir un cycle de cotisation solidaire de 12 mois.";
        }

        return response()->json([
            'eligible'             => $eligible,
            'entity_type'          => $entityType,
            'entity_id'            => $entityId,
            'entity_name'          => $entityName,
            'has_ordinary_account' => $hasAccount,
            'has_been_funded'      => $isFunded,
            'account_balance'      => $balance,
            'has_active_cycle'     => (bool) $activeCycle,
            'active_cycle'         => $activeCycle,
            'message'              => $message,
        ]);
    }

    /** DELETE /api/cycles/clear-all — Effacer tous les cycles du système */
    public function clearAllCycles(Request $request): JsonResponse
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Deposit::query()->update(['cycle_id' => null]);
        Cycle::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        return response()->json([
            'message'      => 'Tous les cycles ont été supprimés avec succès du système.',
            'cycles_count' => 0,
        ]);
    }

    /** GET /api/cycles/current — cycle actuellement actif */
    public function currentCycle(): JsonResponse
    {
        $cycle = Cycle::where('status', 'active')->latest('start_date')->first();
        if (!$cycle) {
            return response()->json(['message' => 'Aucun cycle actif.', 'cycle' => null]);
        }
        $cycle->load(['branch:id,name', 'openedBy:id,name']);
        return response()->json(['cycle' => $cycle]);
    }

    /** POST /api/cycles — ouvrir un nouveau cycle */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type'                  => 'required|string|in:individual,organisation,branch',
            'entity_id'                    => 'required|integer',
            'name'                         => 'nullable|string|max:150',
            'start_date'                   => 'required|date',
            'recovery_date'                => 'nullable|date',
            'auto_deposit_day'             => 'required|integer|min:1|max:28',
            'montant_total_cycle'          => 'required|numeric|min:12',
            'branch_id'                    => 'nullable|integer|exists:branches,id',
            'notes'                        => 'nullable|string',
        ]);

        $entityType = $validated['entity_type'];
        $entityId   = (int) $validated['entity_id'];

        $userId = null;
        $entityName = 'Participant';
        $branchId = $validated['branch_id'] ?? null;

        if ($entityType === 'individual') {
            $ind = Individual::with('user')->find($entityId);
            if (!$ind) {
                return response()->json(['error' => 'Veuillez sélectionner un entrepreneur valide.'], 422);
            }
            $userId = $ind->user_id;
            $branchId = $branchId ?: $ind->branch_id;
            $entityName = $ind->user?->name ?? "Entrepreneur #{$entityId}";
        } elseif ($entityType === 'organisation') {
            $org = Organisation::find($entityId);
            if (!$org) {
                return response()->json(['error' => 'Veuillez sélectionner une organisation valide.'], 422);
            }
            $userId = $org->created_by ?? $request->user()->id;
            $branchId = $branchId ?: $org->branch_id;
            $entityName = $org->name;
        } elseif ($entityType === 'branch') {
            $branch = Branch::find($entityId);
            if (!$branch) {
                return response()->json(['error' => 'Veuillez sélectionner une succursale valide.'], 422);
            }
            $branchId = $branch->id;
            $userId = $branch->manager_user_id ?? $request->user()->id;
            $entityName = "Succursale {$branch->name}";
        }

        // Vérifier le compte ordinaire
        $account = OrdinaryAccount::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->first();

        if (!$account && $userId) {
            $account = OrdinaryAccount::where('user_id', $userId)->first();
        }

        if (!$account || !$account->hasBeenFunded()) {
            return response()->json([
                'error' => "Impossible d'ouvrir le cycle : le compte ordinaire de \"{$entityName}\" n'a jamais été approvisionné. Veuillez d'abord effectuer un dépôt sur son compte ordinaire.",
            ], 422);
        }

        // Vérifier si cette entité a déjà un cycle actif
        $existingActive = Cycle::where('status', 'active')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->first();

        if ($existingActive) {
            return response()->json([
                'error' => "\"{$entityName}\" possède déjà un cycle actif (\"{$existingActive->name}\"). Veuillez le clôturer avant d'en ouvrir un nouveau.",
            ], 422);
        }

        $startDate = Carbon::parse($validated['start_date']);
        $endDate   = $startDate->copy()->addMonths(12)->subDay();
        $totalAmount = (float) $validated['montant_total_cycle'];
        $monthlyAmount = round($totalAmount / 12, 2);

        $recoveryDate = !empty($validated['recovery_date'])
            ? Carbon::parse($validated['recovery_date'])
            : $startDate->copy()->addMonths(12);

        $autoDepositDay = (int) $validated['auto_deposit_day'];

        // Calcul de la première date de prélèvement
        $nextAutoDeposit = Carbon::create($startDate->year, $startDate->month, min($autoDepositDay, $startDate->daysInMonth));
        if ($nextAutoDeposit->lessThan($startDate)) {
            $nextAutoDeposit = $nextAutoDeposit->addMonth();
        }

        $name = !empty($validated['name'])
            ? $validated['name']
            : "Cycle 12M - {$entityName} - " . $startDate->format('Y');

        $cycle = Cycle::create([
            'name'                         => $name,
            'entity_type'                  => $entityType,
            'entity_id'                    => $entityId,
            'user_id'                      => $userId,
            'ordinary_account_id'          => $account->id,
            'year'                         => $startDate->year,
            'start_date'                   => $startDate->toDateString(),
            'end_date'                     => $endDate->toDateString(),
            'recovery_date'                => $recoveryDate->toDateString(),
            'auto_deposit_day'             => $autoDepositDay,
            'auto_deposit_next_date'       => $nextAutoDeposit->toDateString(),
            'montant_total_cycle'          => $totalAmount,
            'montant_cotisation_mensuelle' => $monthlyAmount,
            'total_deposited'              => 0.00,
            'status'                       => 'active',
            'branch_id'                    => $branchId,
            'opened_by_user_id'            => $request->user()->id,
            'notes'                        => $validated['notes'] ?? null,
        ]);

        $cycle->load(['branch:id,name', 'openedBy:id,name', 'ordinaryAccount']);

        // ──────────────────────────────────────────────────────────────────────
        // PRÉLÈVEMENT AUTOMATIQUE IMMÉDIAT : si la date d'auto-dépôt du mois
        // en cours est DÉJÀ PASSÉE, on prélève immédiatement la cotisation du
        // mois depuis le compte ordinaire pour ne pas sauter ce mois.
        // Ex : cycle créé le 28/10, auto_deposit_day=25 → le 25 oct est passé
        //      → on prélève immédiatement la cotisation d'octobre.
        // ──────────────────────────────────────────────────────────────────────
        $immediateDeposit = null;
        $immediateWarning = null;

        $today = Carbon::now()->startOfDay();

        // Date du jour d'auto-dépôt pour le mois de démarrage du cycle
        $cycleStartMonth = Carbon::parse($cycle->start_date)->startOfMonth();
        $depositDayThisMonth = Carbon::create(
            $cycleStartMonth->year,
            $cycleStartMonth->month,
            min($autoDepositDay, $cycleStartMonth->daysInMonth)
        )->startOfDay();

        // Condition : le jour d'auto-dépôt du mois de démarrage est déjà passé
        //             ET le cycle vient juste d'être ouvert (start_date <= aujourd'hui)
        $cycleAlreadyStarted = Carbon::parse($cycle->start_date)->lessThanOrEqualTo($today);

        if ($cycleAlreadyStarted && $depositDayThisMonth->lessThan($today)) {
            $account->refresh();

            DB::beginTransaction();
            try {
                $balanceBefore = (float) $account->balance;
                $balanceAfter  = round($balanceBefore - $monthlyAmount, 2);

                // Débiter le compte ordinaire (autorise solde négatif avec marquage negative_since)
                $account->balance = $balanceAfter;
                if ($balanceAfter < 0 && $account->negative_since === null) {
                    $account->negative_since = now();
                }
                $account->save();

                // Enregistrer la transaction sur le compte ordinaire
                OrdinaryAccountTransaction::create([
                    'ordinary_account_id' => $account->id,
                    'transaction_type'    => 'cotisation_solidaire',
                    'direction'           => 'debit',
                    'amount'              => $monthlyAmount,
                    'balance_before'      => $balanceBefore,
                    'balance_after'       => $balanceAfter,
                    'description'         => "Cotisation solidaire automatique — {$cycle->name} — Mois 1 (jour {$autoDepositDay} déjà passé)",
                    'reference'           => 'COT-' . strtoupper(Str::random(8)),
                    'payment_method'      => 'auto_debit',
                    'operator_id'         => $request->user()->id,
                ]);

                // Créer le dépôt (cotisation solidaire) associé au cycle
                $depositRef = 'DEP-' . strtoupper(Str::random(10));
                $deposit = Deposit::create([
                    'deposit_reference'  => $depositRef,
                    'user_id'            => $userId,
                    'branch_id'          => $branchId,
                    'operator_id'        => $request->user()->id,
                    'cycle_id'           => $cycle->id,
                    'entity_type'        => $entityType,
                    'actual_amount'      => $monthlyAmount,
                    'expected_amount'    => $monthlyAmount,
                    'actual_date'        => $depositDayThisMonth->toDateString(),
                    'expected_date'      => $depositDayThisMonth->toDateString(),
                    'payment_method'     => 'auto_debit',
                    'payment_reference'  => $depositRef,
                    'wallet_destination' => 'caution',
                    'is_on_time'         => true,
                    'delay_days'         => 0,
                    'remarks'            => "Prélèvement automatique à l'ouverture du cycle (jour {$autoDepositDay} déjà passé ce mois).",
                ]);

                // Mettre à jour total_deposited du cycle + next auto deposit date
                $nextAutoDepositDate = Carbon::create(
                    $cycleStartMonth->year,
                    $cycleStartMonth->month,
                    min($autoDepositDay, $cycleStartMonth->daysInMonth)
                )->addMonth();

                $cycle->update([
                    'total_deposited'        => $monthlyAmount,
                    'auto_deposit_next_date'  => $nextAutoDepositDate->toDateString(),
                ]);

                DB::commit();

                $isOverdrawn = $balanceAfter < 0;
                $msg = "La cotisation du mois en cours (jour {$autoDepositDay}) était déjà passée — prélèvement immédiat de {$monthlyAmount} \$ effectué depuis le compte ordinaire.";
                if ($isOverdrawn) {
                    $msg .= " Le solde est désormais négatif ({$balanceAfter} \$). Des intérêts débiteurs s'appliqueront après le délai de grâce.";
                }

                $immediateDeposit = [
                    'deposit_id'     => $deposit->id,
                    'reference'      => $depositRef,
                    'amount'         => $monthlyAmount,
                    'deducted_from'  => 'compte_ordinaire',
                    'balance_before' => $balanceBefore,
                    'balance_after'  => $balanceAfter,
                    'is_overdrawn'   => $isOverdrawn,
                    'date'           => $depositDayThisMonth->toDateString(),
                    'message'        => $msg,
                ];
            } catch (\Throwable $e) {
                DB::rollBack();
                $immediateWarning = "Cycle créé, mais le prélèvement automatique immédiat a échoué : " . $e->getMessage();
            }
        }

        $cycle->refresh()->load(['branch:id,name', 'openedBy:id,name', 'ordinaryAccount']);

        $response = [
            'message' => "Cycle de 12 mois pour \"{$entityName}\" ouvert avec succès.",
            'cycle'   => $cycle,
        ];

        if ($immediateDeposit) {
            $response['immediate_deposit'] = $immediateDeposit;
        }

        if ($immediateWarning) {
            $response['warning'] = $immediateWarning;
        }

        return response()->json($response, 201);
    }

    /** POST /api/cycles/{id}/close — fermer un cycle actif */
    public function close(Request $request, int $id): JsonResponse
    {
        $cycle = Cycle::findOrFail($id);

        if ($cycle->status !== 'active') {
            return response()->json(['error' => 'Ce cycle n\'est pas actif.'], 422);
        }

        // Calculer les totaux finaux avant fermeture
        $totalCollected = Deposit::where('cycle_id', $cycle->id)->sum('actual_amount');
        $totalMembers   = Deposit::where('cycle_id', $cycle->id)->distinct('user_id')->count('user_id');

        $cycle->update([
            'status'             => 'closed',
            'closed_at'          => now(),
            'closed_by_user_id'  => $request->user()->id,
            'total_collected'    => $totalCollected,
            'total_members'      => $totalMembers,
        ]);

        return response()->json([
            'message'          => "Cycle \"{$cycle->name}\" fermé avec succès.",
            'cycle'            => $cycle->fresh(),
            'total_collected'  => $totalCollected,
            'total_members'    => $totalMembers,
        ]);
    }

    /**
     * GET /api/cycles/{id}/settlement-preview
     * Prévisualise la liquidation complète de fin de cycle :
     * Dépôts cotisés + Bonus acquis - Malus imputés = Solde Net par participant
     */
    public function settlementPreview(int $id): JsonResponse
    {
        $cycle = Cycle::findOrFail($id);

        $deposits = Deposit::where('cycle_id', $cycle->id)
            ->with(['user.individual', 'branch:id,name'])
            ->get();

        $groupedByUser = $deposits->groupBy('user_id');

        $members = [];
        $totalDepositsAll = 0.0;
        $totalBonusAll    = 0.0;
        $totalMalusAll    = 0.0;
        $totalNetAll      = 0.0;

        $startDate = Carbon::parse($cycle->start_date)->startOfDay();
        $endDate   = Carbon::parse($cycle->end_date ?? now())->endOfDay();

        foreach ($groupedByUser as $userId => $userDeposits) {
            $firstDeposit = $userDeposits->first();
            $user = $firstDeposit->user;
            $individual = $user?->individual;

            $depotSum = (float) $userDeposits->sum('actual_amount');

            // Bonus obtenus durant la durée du cycle
            $bonusSum = (float) Bonus::where('user_id', $userId)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum('amount');

            // Malus encourus durant la durée du cycle
            $malusSum = (float) Malus::where('user_id', $userId)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum('penalty_amount');

            $netBalance = round(max(0, $depotSum + $bonusSum - $malusSum), 2);

            $members[] = [
                'user_id'         => $userId,
                'name'            => $user?->name ?? 'Participant #' . $userId,
                'phone'           => $user?->phone ?? '-',
                'member_code'     => $individual?->member_code ?? 'MEM-' . str_pad($userId, 4, '0', STR_PAD_LEFT),
                'branch_id'       => $firstDeposit->branch_id,
                'branch_name'     => $firstDeposit->branch?->name ?? '-',
                'total_deposits'  => $depotSum,
                'total_bonuses'   => $bonusSum,
                'total_maluses'   => $malusSum,
                'net_balance'     => $netBalance,
                'deposits_count'  => $userDeposits->count(),
            ];

            $totalDepositsAll += $depotSum;
            $totalBonusAll    += $bonusSum;
            $totalMalusAll    += $malusSum;
            $totalNetAll      += $netBalance;
        }

        return response()->json([
            'cycle'           => $cycle,
            'summary'         => [
                'members_count'   => count($members),
                'total_deposits'  => round($totalDepositsAll, 2),
                'total_bonuses'   => round($totalBonusAll, 2),
                'total_maluses'   => round($totalMalusAll, 2),
                'total_net'       => round($totalNetAll, 2),
            ],
            'participants'    => $members,
        ]);
    }

    /**
     * POST /api/cycles/{id}/settle-and-rollover
     * Solde le cycle courant :
     * - Pour les participants cochés (rollover) : solde net transféré vers le nouveau cycle
     * - Pour les autres : solde retiré/liquidé
     * - Clôture le cycle
     */
    public function settleAndRollover(Request $request, int $id): JsonResponse
    {
        $cycle = Cycle::findOrFail($id);

        $validated = $request->validate([
            'rollover_user_ids' => 'nullable|array',
            'rollover_user_ids.*' => 'integer',
            'target_cycle_id'   => 'nullable|integer|exists:cycles,id',
            'close_cycle'       => 'nullable|boolean',
        ]);

        $rolloverUserIds = collect($validated['rollover_user_ids'] ?? []);
        $targetCycleId   = $validated['target_cycle_id'] ?? null;
        $shouldClose     = $validated['close_cycle'] ?? true;

        // Trouver ou valider le cycle cible si rollover demandé
        $targetCycle = null;
        if ($rolloverUserIds->isNotEmpty()) {
            if ($targetCycleId) {
                $targetCycle = Cycle::findOrFail($targetCycleId);
            } else {
                // Trouver le cycle actif le plus récent autre que celui-ci
                $targetCycle = Cycle::where('status', 'active')
                    ->where('id', '!=', $cycle->id)
                    ->latest('start_date')
                    ->first();
            }
        }

        $previewResponse = $this->settlementPreview($id);
        $previewData = $previewResponse->getData(true);
        $participants = $previewData['participants'] ?? [];

        $rolledOverCount = 0;
        $totalRolledOverAmount = 0.0;
        $settledCount = 0;
        $totalSettledAmount = 0.0;

        foreach ($participants as $p) {
            $userId     = (int) $p['user_id'];
            $netBalance = (float) $p['net_balance'];

            if ($rolloverUserIds->contains($userId) && $targetCycle && $netBalance > 0) {
                // Créer le versement de report dans le nouveau cycle
                Deposit::create([
                    'user_id'           => $userId,
                    'cycle_id'          => $targetCycle->id,
                    'deposit_reference' => 'ROLL-' . strtoupper(Str::random(6)),
                    'actual_amount'     => $netBalance,
                    'payment_method'    => 'cash',
                    'is_on_time'        => true,
                    'remarks'           => "Report de caution (Rollover) du cycle {$cycle->name}",
                    'status'            => 'approved',
                    'branch_id'         => $p['branch_id'] ?? $cycle->branch_id,
                ]);

                $rolledOverCount++;
                $totalRolledOverAmount += $netBalance;
            } else {
                // Participant soldé / remboursé
                $settledCount++;
                $totalSettledAmount += $netBalance;
            }
        }

        // Fermer le cycle si demandé
        if ($shouldClose && $cycle->status === 'active') {
            $totalCollected = Deposit::where('cycle_id', $cycle->id)->sum('actual_amount');
            $cycle->update([
                'status'            => 'closed',
                'closed_at'         => now(),
                'closed_by_user_id' => $request->user()->id,
                'total_collected'   => $totalCollected,
                'total_members'     => count($participants),
            ]);
        }

        return response()->json([
            'message'                   => "Liquidation du cycle \"{$cycle->name}\" effectuée avec succès.",
            'rolled_over_count'         => $rolledOverCount,
            'total_rolled_over_amount'  => round($totalRolledOverAmount, 2),
            'settled_count'             => $settledCount,
            'total_settled_amount'      => round($totalSettledAmount, 2),
            'target_cycle'              => $targetCycle ? ['id' => $targetCycle->id, 'name' => $targetCycle->name] : null,
            'cycle'                     => $cycle->fresh(),
        ]);
    }

    /**
     * POST /api/cycles/{id}/block — bloquer un cycle et geler son compte ordinaire
     */
    public function blockCycle(Request $request, int $id): JsonResponse
    {
        $cycle = Cycle::findOrFail($id);
        $user = $request->user();

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $cycle->update([
            'is_blocked'         => true,
            'blocked_at'         => now(),
            'blocked_by_user_id' => $user ? $user->id : null,
            'block_reason'       => $validated['reason'],
        ]);

        // Bloquer également le compte ordinaire correspondant
        if ($cycle->ordinary_account_id) {
            $acc = OrdinaryAccount::find($cycle->ordinary_account_id);
            if ($acc) {
                $acc->update(['status' => 'blocked']);
            }
        } elseif ($cycle->user_id) {
            $acc = OrdinaryAccount::where('user_id', $cycle->user_id)->first();
            if ($acc) {
                $acc->update(['status' => 'blocked']);
            }
        } elseif ($cycle->individual_id) {
            $acc = OrdinaryAccount::where('entity_type', 'individual')->where('entity_id', $cycle->individual_id)->first();
            if ($acc) {
                $acc->update(['status' => 'blocked']);
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Le cycle et le compte ordinaire associé ont été bloqués avec succès.',
            'cycle'   => $cycle->fresh(['ordinaryAccount', 'branch', 'user']),
        ]);
    }

    /**
     * POST /api/cycles/{id}/unblock — débloquer un cycle
     */
    public function unblockCycle(Request $request, int $id): JsonResponse
    {
        $cycle = Cycle::findOrFail($id);
        $cycle->update([
            'is_blocked'         => false,
            'blocked_at'         => null,
            'blocked_by_user_id' => null,
            'block_reason'       => null,
        ]);

        // Débloquer également le compte ordinaire
        if ($cycle->ordinary_account_id) {
            $acc = OrdinaryAccount::find($cycle->ordinary_account_id);
            if ($acc) {
                $acc->update(['status' => 'active']);
            }
        } elseif ($cycle->user_id) {
            $acc = OrdinaryAccount::where('user_id', $cycle->user_id)->first();
            if ($acc) {
                $acc->update(['status' => 'active']);
            }
        } elseif ($cycle->individual_id) {
            $acc = OrdinaryAccount::where('entity_type', 'individual')->where('entity_id', $cycle->individual_id)->first();
            if ($acc) {
                $acc->update(['status' => 'active']);
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'Le cycle a été débloqué avec succès.',
            'cycle'   => $cycle->fresh(['ordinaryAccount', 'branch', 'user']),
        ]);
    }

    /**
     * POST /api/cycles/{id}/disburse-to-ordinary-account
     * Clôture / versement du solde net (Bonus & Malus) dans le compte ordinaire.
     * RÈGLES STRICTES :
     * 1. On ne peut pas verser sans que le cycle ne soit terminé (status == 'closed') ou bloqué (is_blocked == true).
     * 2. Si le cycle est bloqué : TOUS les bonus sont annulés (0 bonus), et TOUS les malus sont déduits.
     * 3. Génère et retourne le rapport certifié prêt à être imprimé et signé par l'entrepreneur.
     */
    public function disburseToOrdinaryAccount(Request $request, int $id): JsonResponse
    {
        $cycle = Cycle::findOrFail($id);

        if ($cycle->status !== 'closed' && !$cycle->is_blocked) {
            return response()->json([
                'error' => 'Action refusée : Le cycle doit être préalablement terminé et clôturé avant de pouvoir verser les bonus et malus dans le compte ordinaire.'
            ], 422);
        }

        $validated = $request->validate([
            'user_id'         => 'nullable|integer|exists:users,id',
            'signed_by_user'  => 'nullable|boolean',
            'notes'           => 'nullable|string|max:500',
        ]);

        $depositsQuery = Deposit::where('cycle_id', $cycle->id)
            ->with(['user.individual', 'branch:id,name']);

        if (!empty($validated['user_id'])) {
            $depositsQuery->where('user_id', $validated['user_id']);
        }

        $deposits = $depositsQuery->get();
        if ($deposits->isEmpty()) {
            return response()->json([
                'error' => 'Aucune caution / cotisation enregistrée pour ce cycle.'
            ], 422);
        }

        $groupedByUser = $deposits->groupBy('user_id');
        $disbursedParticipants = [];
        $totalDisbursed = 0.0;
        $isBlocked = (bool) $cycle->is_blocked;

        $startDate = Carbon::parse($cycle->start_date)->startOfDay();
        $endDate   = Carbon::parse($cycle->end_date ?? now())->endOfDay();

        $accountService = app(\App\Services\OrdinaryAccountService::class);

        DB::beginTransaction();
        try {
            foreach ($groupedByUser as $userId => $userDeposits) {
                $firstDeposit = $userDeposits->first();
                $user = $firstDeposit->user;
                $individual = $user?->individual;

                $depotSum = (float) $userDeposits->sum('actual_amount');

                // Bonus et Malus durant la durée du cycle
                $rawBonusSum = (float) Bonus::where('user_id', $userId)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->sum('amount');

                $rawMalusSum = (float) Malus::where('user_id', $userId)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->sum('penalty_amount');

                // Règle cycle bloqué : 0 bonus, tous les malus sont soustraits
                if ($isBlocked) {
                    $effectiveBonus = 0.0;
                    $effectiveMalus = $rawMalusSum;
                    $netDisbursed   = round(max(0, $depotSum - $effectiveMalus), 2);
                } else {
                    $effectiveBonus = $rawBonusSum;
                    $effectiveMalus = $rawMalusSum;
                    $netDisbursed   = round(max(0, $depotSum + $effectiveBonus - $effectiveMalus), 2);
                }

                // Trouver ou créer le compte ordinaire
                if ($individual) {
                    $ordinaryAccount = OrdinaryAccount::getOrCreateForEntity('individual', $individual->id, $userId);
                } else {
                    $ordinaryAccount = OrdinaryAccount::getOrCreateForEntity('user', $userId, $userId);
                }

                if ($netDisbursed > 0) {
                    $desc = $isBlocked 
                        ? "Versement clôture cycle bloqué [{$cycle->name}] (Bonus: $0.00, Malus déduits: -\$" . number_format($effectiveMalus, 2) . ")"
                        : "Versement clôture cycle [{$cycle->name}] (Caution: \${$depotSum} + Bonus: \${$effectiveBonus} - Malus: \${$effectiveMalus})";

                    $accountService->deposit(
                        $ordinaryAccount,
                        $netDisbursed,
                        'cycle_settlement',
                        $desc,
                        $request->user()?->id,
                        'deposit'
                    );
                }

                $reportItem = [
                    'user_id'          => $userId,
                    'member_name'      => $user?->name ?? 'Membre #' . $userId,
                    'member_code'      => $individual?->member_code ?? 'MEM-' . str_pad($userId, 4, '0', STR_PAD_LEFT),
                    'phone'            => $user?->phone ?? '-',
                    'total_deposits'   => $depotSum,
                    'raw_bonus'        => $rawBonusSum,
                    'applied_bonus'    => $effectiveBonus,
                    'applied_malus'    => $effectiveMalus,
                    'net_disbursed'    => $netDisbursed,
                    'is_blocked'       => $isBlocked,
                    'account_balance'  => $ordinaryAccount->fresh()->balance,
                    'generated_at'     => now()->format('d/m/Y H:i'),
                ];

                $disbursedParticipants[] = $reportItem;
                $totalDisbursed += $netDisbursed;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Erreur lors du versement : ' . $e->getMessage()], 500);
        }

        return response()->json([
            'status'                => true,
            'message'               => 'Versement du solde net dans le compte ordinaire effectué avec succès.',
            'total_disbursed'       => round($totalDisbursed, 2),
            'participants_count'    => count($disbursedParticipants),
            'cycle'                 => [
                'id'         => $cycle->id,
                'name'       => $cycle->name,
                'start_date' => $cycle->start_date,
                'end_date'   => $cycle->end_date,
                'status'     => $cycle->status,
                'is_blocked' => (bool) $cycle->is_blocked,
            ],
            'disbursements'         => $disbursedParticipants,
            'signature_template'    => [
                'approved_by_admin'    => $request->user()?->name ?? 'Administration GEP',
                'approved_at'          => now()->format('d/m/Y H:i'),
                'entrepreneur_notice'  => 'Je soussigné certifie avoir pris connaissance du solde net et des bonus/malus imputés à la clôture de mon cycle.',
            ],
        ]);
    }

    /** GET /api/cycles/{id}/stats — statistiques détaillées d'un cycle */
    public function stats(int $id): JsonResponse
    {
        $cycle = Cycle::findOrFail($id);

        $deposits = Deposit::where('cycle_id', $cycle->id)
            ->with('user:id,name,phone')
            ->get();

        $totalCollected  = $deposits->sum('actual_amount');
        $totalMembers    = $deposits->pluck('user_id')->unique()->count();
        $lateDeposits    = $deposits->where('is_on_time', false)->count();
        $onTimeDeposits  = $deposits->where('is_on_time', true)->count();
        $regularityRate  = $deposits->count() > 0
            ? round($onTimeDeposits / $deposits->count() * 100, 1)
            : 0;

        // Répartition par mois de cycle
        $byMonth = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthDeposits = $deposits->filter(function ($d) use ($m) {
                return str_contains($d->remarks ?? '', "mois #{$m}");
            });
            $byMonth["mois_$m"] = [
                'count'     => $monthDeposits->count(),
                'total'     => $monthDeposits->sum('actual_amount'),
                'on_time'   => $monthDeposits->where('is_on_time', true)->count(),
                'late'      => $monthDeposits->where('is_on_time', false)->count(),
            ];
        }

        return response()->json([
            'cycle'           => $cycle,
            'total_collected' => $totalCollected,
            'total_members'   => $totalMembers,
            'late_deposits'   => $lateDeposits,
            'on_time_deposits'=> $onTimeDeposits,
            'regularity_rate' => $regularityRate,
            'by_month'        => $byMonth,
        ]);
    }

    /**
     * GET /api/cycles/tracking
     * Suivi dynamique des cycles par entité (progression, étoiles, dépôts, échéances, rappels et top)
     */
    public function tracking(Request $request): JsonResponse
    {
        $branchId = $request->query('branch_id');
        $search   = trim((string) $request->query('search', ''));
        $now      = Carbon::now()->startOfDay();

        $allEntities = [];

        // ──────────────────────────────────────────────────────────────────────
        // On part UNIQUEMENT des cycles actifs enregistrés en base (table cycles)
        // afin d'éviter d'afficher des "cycles fantômes" basés sur les dépôts.
        // ──────────────────────────────────────────────────────────────────────
        $cyclesQuery = Cycle::with([
            'individual.user',
            'individual.branch',
            'organisation.branch',
            'branch',
        ])->whereIn('status', ['active', 'planned']);

        if ($branchId) {
            $cyclesQuery->where('branch_id', $branchId);
        }

        $cycles = $cyclesQuery->get();

        foreach ($cycles as $cycle) {
            $entityType = $cycle->entity_type ?? 'individual';
            $entityId   = null;
            $entityName = $cycle->name ?? 'Cycle';
            $code       = '';
            $phone      = '-';
            $branchName = 'Succursale Principale';
            $stars      = 3.0;
            $streakMonths = 0;
            $userId     = null;

            // Résoudre l'entité associée
            if ($entityType === 'individual' && $cycle->individual) {
                $ind  = $cycle->individual;
                $user = $ind->user;
                if (!$user) continue;
                $entityId   = $ind->id;
                $userId     = $user->id;
                $entityName = $user->name;
                $code       = $ind->member_code ?? ('MBR-' . str_pad($user->id, 4, '0', STR_PAD_LEFT));
                $phone      = $user->phone ?? '-';
                $branchName = $ind->branch?->name ?? 'Succursale Principale';
                $stars      = (float) ($ind->cotisation_stars ?? 3.0);
                $streakMonths = (int) ($ind->deposit_streak_months ?? 0);
            } elseif ($entityType === 'organisation' && $cycle->organisation) {
                $org        = $cycle->organisation;
                $entityId   = $org->id;
                $entityName = $org->name;
                $code       = $org->code ?? ('ORG-' . str_pad($org->id, 4, '0', STR_PAD_LEFT));
                $phone      = $org->phone ?? '-';
                $branchName = $org->branch?->name ?? 'Succursale Principale';
                $stars      = 4.0;
            } elseif ($entityType === 'branch' && $cycle->branch) {
                $br         = $cycle->branch;
                $entityId   = $br->id;
                $entityName = $br->name;
                $code       = $br->code ?? ('BR-' . str_pad($br->id, 3, '0', STR_PAD_LEFT));
                $phone      = $br->phone ?? '-';
                $branchName = $br->name;
                $stars      = 5.0;
            }

            if ($entityId === null) continue;

            // Filtre de recherche
            if ($search !== '') {
                $match = str_contains(strtolower($entityName), strtolower($search))
                    || str_contains(strtolower($code), strtolower($search))
                    || str_contains(strtolower($phone), strtolower($search));
                if (!$match) continue;
            }

            // Dépôts liés à ce cycle
            $depositsQuery = Deposit::where('cycle_id', $cycle->id)->orderBy('actual_date');
            // Fallback : si aucun dépôt avec cycle_id, chercher par user/org
            $deposits = $depositsQuery->get();
            if ($deposits->isEmpty() && $userId) {
                $deposits = Deposit::where('user_id', $userId)->orderBy('actual_date')->get();
            }

            $count       = $deposits->count();
            $totalAmount = (float) $deposits->sum('actual_amount');

            $startDate   = Carbon::parse($cycle->start_date)->startOfDay();
            $endDate     = Carbon::parse($cycle->end_date ?? $startDate->copy()->addMonths(12)->subDay())->startOfDay();
            $recoveryDate = $cycle->recovery_date ? Carbon::parse($cycle->recovery_date)->startOfDay() : $endDate->copy()->addDay();

            $nextDueDate   = $startDate->copy()->addMonths($count);
            $daysUntilDue  = (int) $now->diffInDays($nextDueDate, false);

            $cycleMonth  = ($count % 12) + 1;
            $progressPct = min(100, round(($count / 12) * 100, 1));

            $isNew                  = $count <= 1;
            $isEnding               = $count >= 11 || ($now->lessThanOrEqualTo($endDate) && $now->diffInDays($endDate, false) <= 30);
            $isDueIn6Days           = $daysUntilDue >= 0 && $daysUntilDue <= 6;
            $hasPenalties6DaysAfter = ($daysUntilDue <= -6) || ($deposits->where('is_on_time', false)->where('delay_days', '>=', 6)->count() > 0);

            $depositList = $deposits->map(function ($d) {
                return [
                    'id'                => $d->id,
                    'reference'         => $d->deposit_reference,
                    'amount'            => (float) $d->actual_amount,
                    'deposit_date'      => $d->actual_date ? Carbon::parse($d->actual_date)->format('d/m/Y') : ($d->created_at ? $d->created_at->format('d/m/Y') : '-'),
                    'payment_method'    => $d->payment_method ?? 'cash',
                    'payment_reference' => $d->payment_reference ?? '-',
                    'is_on_time'        => (bool) $d->is_on_time,
                    'delay_days'        => (int) ($d->delay_days ?? 0),
                    'remarks'           => $d->remarks ?? '-',
                ];
            })->values();

            $allEntities[] = [
                'id'                         => $entityType . '_' . $entityId,
                'cycle_id'                   => $cycle->id,
                'entity_id'                  => $entityId,
                'raw_id'                     => $entityId,
                'entity_type'                => $entityType,
                'name'                       => $entityName,
                'code'                       => $code,
                'phone'                      => $phone,
                'branch_id'                  => $cycle->branch_id,
                'branch_name'                => $branchName,
                'stars'                      => $stars,
                'streak_months'              => $streakMonths,
                'deposits_count'             => $count,
                'total_amount'               => $totalAmount,
                'montant_total_cycle'        => (float) ($cycle->montant_total_cycle ?? 0),
                'montant_cotisation_mensuelle'=> (float) ($cycle->montant_cotisation_mensuelle ?? 0),
                'progress_pct'               => $progressPct,
                'cycle_month'                => $cycleMonth,
                'start_date'                 => $startDate->format('d/m/Y'),
                'end_date'                   => $endDate->format('d/m/Y'),
                'recovery_date'              => $recoveryDate->format('d/m/Y'),
                'next_due_date'              => $nextDueDate->format('d/m/Y'),
                'days_until_due'             => $daysUntilDue,
                'cycle_status'               => $cycle->status,
                'is_new_cycle'               => $isNew,
                'is_ending_soon'             => $isEnding,
                'is_due_in_6_days'           => $isDueIn6Days,
                'has_penalties_6_days_after' => $hasPenalties6DaysAfter,
                'deposits'                   => $depositList,
            ];
        }

        // 2. Organisations (ancienne logique — SUPPRIMÉE, maintenant gérée via cycles)
        // 3. Succursales (ancienne logique — SUPPRIMÉE, maintenant gérée via cycles)

        // ── Collections filtrées ───────────────────────────────────────────────
        // NOTE: anciens blocs Organisation et Succursale supprimés — tout passe
        // désormais par la table `cycles` pour garantir cohérence avec la BD.
        // (Bloc organisation héritage — conservé pour référence dans git history)

        // Collections filtrées
        $newCycles = array_values(array_filter($allEntities, fn($e) => $e['is_new_cycle']));
        $endingSoon = array_values(array_filter($allEntities, fn($e) => $e['is_ending_soon']));
        $remindersDueIn6Days = array_values(array_filter($allEntities, fn($e) => $e['is_due_in_6_days']));
        $remindersPenalties6DaysAfter = array_values(array_filter($allEntities, fn($e) => $e['has_penalties_6_days_after']));

        // Top cotisants triés par total déposé, puis par étoiles
        $topEntities = $allEntities;
        usort($topEntities, function ($a, $b) {
            if ($b['total_amount'] != $a['total_amount']) {
                return $b['total_amount'] <=> $a['total_amount'];
            }
            if ($b['stars'] != $a['stars']) {
                return $b['stars'] <=> $a['stars'];
            }
            return $b['deposits_count'] <=> $a['deposits_count'];
        });

        return response()->json([
            'all_entities'                     => $allEntities,
            'new_cycles'                       => $newCycles,
            'ending_soon'                      => $endingSoon,
            'reminders_due_in_6_days'          => $remindersDueIn6Days,
            'reminders_penalties_6_days_after' => $remindersPenalties6DaysAfter,
            'top_entities'                     => array_slice($topEntities, 0, 15),
            'counts'                           => [
                'total'             => count($allEntities),
                'new'               => count($newCycles),
                'ending'            => count($endingSoon),
                'due_in_6'          => count($remindersDueIn6Days),
                'penalties_after_6' => count($remindersPenalties6DaysAfter),
            ],
        ]);
    }

}
