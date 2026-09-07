<?php

namespace App\Exports;

use App\Contract\Admin\AuditViewContract;
use App\Models\AuditLog;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Section 14 phase 6. An audit trail that cannot leave the system is not much
 * use to the people who usually ask for one - a customer's legal team, or an
 * auditor who will not be given a console login.
 */
class AuditTrailExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        private readonly AuditViewContract $audit,
        private readonly ?string $term,
        private readonly ?string $action,
    ) {}

    public function collection(): Collection
    {
        return collect($this->audit->search($this->term, $this->action, 5000)->items());
    }

    /** @return string[] */
    public function headings(): array
    {
        return ['When', 'Action', 'Who', 'Kind', 'As customer', 'Workspace', 'Detail', 'IP'];
    }

    /**
     * @param  AuditLog  $row
     * @return array<int, string|null>
     */
    public function map($row): array
    {
        $entry = $this->audit->present($row);

        return [
            $entry['created_at']?->toDateTimeString(),
            $entry['action'],
            $entry['actor'] ?? 'System',
            $entry['actor_kind'],
            // Section 10: "the customer did this" has to stay separable from
            // "we did this as them", including on paper.
            $entry['via_impersonation'] ? 'yes' : 'no',
            $entry['workspace_name'],
            $entry['changes'] === null ? null : json_encode($entry['changes']),
            $entry['ip_address'],
        ];
    }
}
