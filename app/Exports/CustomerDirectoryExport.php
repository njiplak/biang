<?php

namespace App\Exports;

use App\Contract\Admin\CustomerContract;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Section 14 phase 6: exports. The customer directory is the one staff ask for
 * first - a board pack, a churn review, a list to work through by hand.
 *
 * Reuses CustomerContract::search so the export and the screen can never
 * disagree about what "a customer" means, including reading through the soft
 * delete for closed workspaces.
 */
class CustomerDirectoryExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(
        private readonly CustomerContract $customers,
        private readonly ?string $term,
    ) {}

    public function collection(): Collection
    {
        // One page big enough to be the whole directory. Section 15's numbers
        // are counted in the thousands, not millions; if that changes this
        // becomes FromQuery with chunking.
        return collect($this->customers->search($this->term, 5000)->items());
    }

    /** @return string[] */
    public function headings(): array
    {
        return ['Workspace', 'Slug', 'State', 'Plan', 'Billing source', 'Members', 'Signed up'];
    }

    /**
     * @param  Workspace  $row
     * @return array<int, string|int|null>
     */
    public function map($row): array
    {
        $summary = $this->customers->summarise($row);

        return [
            $summary['name'],
            $summary['slug'],
            $summary['state_label'],
            $summary['plan'] ?? 'Free',
            // Section 8: a comp has no payment behind it, and a revenue review
            // that counts one as paying is wrong in the direction that matters.
            $summary['billing_source'] ?? 'none',
            $summary['members_count'],
            $summary['created_at']?->toDateString(),
        ];
    }
}
