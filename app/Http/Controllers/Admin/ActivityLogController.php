<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit trail, read-only.
 *
 * Superadmin only. The trail records what every other role did, so putting it
 * behind a role that can also be reviewed by it would defeat the purpose.
 *
 * There is no delete and no export here on purpose: an audit log that can be
 * pruned from the UI it audits is not evidence of anything.
 */
class ActivityLogController extends Controller
{
    /** The action families worth filtering by, in the order they appear. */
    private const MODULES = [
        'registration' => 'Registrations',
        'payment' => 'Payments',
        'refund' => 'Refunds',
        'certificate' => 'Certificates',
        'cancellation' => 'Withdrawals',
        'training-request' => 'Training Requests',
        'training' => 'Trainings',
    ];

    public function index(Request $request): Response
    {
        $module = $request->string('module')->toString();
        $search = $request->string('search')->toString();

        $logs = ActivityLog::with('causer')
            ->when($module !== '' && isset(self::MODULES[$module]), fn ($query) => $query->inModule($module))
            ->when($search !== '', fn ($query) => $query->where(
                fn ($inner) => $inner->where('description', 'like', "%{$search}%")
                    ->orWhere('causer_name', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/ActivityLog', [
            'logs' => $logs->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'actor' => $log->actorName(),
                'subject' => $log->subject_type === null
                    ? null
                    : class_basename($log->subject_type).' #'.$log->subject_id,
                'ip_address' => $log->ip_address,
                'properties' => $log->properties,
                'at' => $log->created_at->format('d M Y, g:i:s A'),
            ]),
            'filters' => ['module' => $module, 'search' => $search],
            'modules' => array_map(
                fn ($label, $value) => ['value' => $value, 'label' => $label],
                self::MODULES,
                array_keys(self::MODULES),
            ),
        ]);
    }
}
