<?php

namespace App\Observers;

use App\Models\Document;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class DocumentObserver
{
    /** ล็อกกันสร้างรหัสเอกสารซ้ำ ถือไว้ตั้งแต่ออกรหัสจนบันทึกเสร็จ (static เพราะ Laravel สร้าง observer ใหม่ทุก event) */
    private static ?Lock $codeLock = null;

    public function creating(Document $document): void
    {
        $prefix = "D" . now()->format('y') . now()->format('m');

        // ผู้สร้างเอกสารสองคนพร้อมกันต้องไม่ได้รหัสเดียวกัน (ล็อกหมดอายุเองใน 10 วินาทีถ้าบันทึกไม่สำเร็จ)
        self::$codeLock = Cache::lock('document-code', 10);

        try {
            self::$codeLock->block(5);
        } catch (LockTimeoutException) {
            // ล็อกค้าง (บันทึกฉบับก่อนหน้าไม่สำเร็จ) ไปต่อโดยไม่มีล็อก ยังมี unique ของ code กันซ้ำอยู่
            self::$codeLock = null;
        }

        // เลขท้ายที่มากที่สุดของเดือนนี้ (รวมที่ถูกลบ) บวกหนึ่ง ไม่นับจำนวนแถว เพราะแถวที่ลบถาวรจะทำให้เลขซ้ำ
        $last = Document::withTrashed()
            ->where('code', 'like', $prefix . '%')
            ->max('code');
        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        $document->code = $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
        $document->creator_id = auth()->user()->id;
    }

    /**
     * Handle the Document "created" event.
     */
    public function created(Document $document): void
    {
        self::$codeLock?->release();
        self::$codeLock = null;
    }

    public function updating(Document $document): void
    {
        if ($document->isDirty('file_path')) {
            Storage::disk('local')->delete($document->getOriginal('file_path'));

            // ผู้แก้เลือกว่าไม่ต้องรับทราบใหม่ (เช่น แก้คำผิด): คงรอบเดิมและสถานะอีเมลเดิม
            if ($document->keepRevision) {
                return;
            }

            // ไฟล์เปลี่ยน = เอกสารฉบับใหม่ ต้องรับทราบรอบใหม่ (ประวัติรอบเก่ายังอยู่ที่ document_acknowledges)
            // และเปิดให้ส่งอีเมลแจ้งเตือนอีกครั้ง
            $document->revision = (int) $document->getOriginal('revision') + 1;
            $document->notified_at = null;
        }
    }
    /**
     * Handle the Document "updated" event.
     */
    public function updated(Document $document): void
    {
        //
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
     * ลบไฟล์เฉพาะตอนลบถาวร: soft delete ต้องกู้เอกสารคืนพร้อมไฟล์ได้
     */
    public function forceDeleted(Document $document): void
    {
        Storage::disk('local')->delete($document->file_path);
    }
}
