<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromView;

class FollowExport implements FromView
{
    protected $document;

    public function __construct($document)
    {
        return $this->document = $document;
    }

    /**
     * ผู้ใช้ในแผนกที่ต้องรับทราบ ที่รับทราบเอกสารนี้แล้ว (ใช้ร่วมกับหน้าพิมพ์ PDF)
     *
     * @return Collection<int, User>
     */
    public function users(): Collection
    {
        return User::active()->whereIn('section_id', $this->document->acknowledge_sections)
            ->with(['section', 'acknowledges' => function ($query) {
                $query->where('document_id', $this->document->id)
                    ->where('revision', $this->document->revision);
            }])
            ->get()
            ->filter(fn (User $user) => $user->isAcknowledged($this->document->id, $this->document->revision))
            ->values();
    }

    public function view(): View
    {
        return view('exports.follow', [
            'document' => $this->document,
            'users' => $this->users(),
        ]);
    }
}
