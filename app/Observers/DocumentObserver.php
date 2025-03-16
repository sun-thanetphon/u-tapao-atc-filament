<?php

namespace App\Observers;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;

class DocumentObserver
{

    public function creating(Document $document): void
    {
        $prefix = "D" . now()->format('y') . now()->format('m');

        $documents = Document::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->get();

        $lastId = count($documents);
        $document->code = $prefix . str_pad($lastId + 1, 4, '0', STR_PAD_LEFT);
        $document->creator_id = auth()->user()->id;
    }
    /**
     * Handle the Document "created" event.
     */
    public function created(Document $document): void
    {
        //
    }

    public function updating(Document $document): void
    {
        if ($document->isDirty('file_path')) {
            Storage::disk('local')->delete($document->getOriginal('file_path'));
        }
    }
    /**
     * Handle the Document "updated" event.
     */
    public function updated(Document $document): void
    {
        //
    }

    public function deleting(Document $document): void
    {
        Storage::disk('local')->delete($document->file_path);
    }

    /**
     * Handle the Document "deleted" event.
     */
    public function deleted(Document $document): void
    {
        //
    }

    /**
     * Handle the Document "restored" event.
     */
    public function restored(Document $document): void
    {
        //
    }

    /**
     * Handle the Document "force deleted" event.
     */
    public function forceDeleted(Document $document): void
    {
        //
    }
}