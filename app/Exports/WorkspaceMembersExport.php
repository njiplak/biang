<?php

namespace App\Exports;

use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Section 6: a suspended workspace keeps "read, export only" - so getting your
 * data out must survive the states that stop you writing. That is the whole
 * point of not locking people out when something goes wrong.
 */
class WorkspaceMembersExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Workspace $workspace) {}

    public function collection(): Collection
    {
        return $this->workspace->members()->with('user:id,name,email')->get();
    }

    /** @return string[] */
    public function headings(): array
    {
        return ['Name', 'Email', 'Role', 'Joined'];
    }

    /**
     * @param  WorkspaceMember  $row
     * @return array<int, string|null>
     */
    public function map($row): array
    {
        return [
            $row->user?->name,
            $row->user?->email,
            $row->role->label(),
            $row->joined_at?->toDateString(),
        ];
    }
}
