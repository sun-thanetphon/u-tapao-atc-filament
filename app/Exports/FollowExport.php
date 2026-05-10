<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;

class FollowExport implements FromView
{
    protected $document;

    public function __construct($document)
    {
        return $this->document = $document;
    }

    public function view(): View
    {
        $users = User::whereIn('section_id', $this->document->acknowledge_sections)
        ->with(['acknowledges' => function($query) {
            $query->where('document_id', $this->document->id);
        }])
        ->get();

        return view('exports.follow', [
            'document' => $this->document,
            'users' =>  $users
        ]);
    }
}
