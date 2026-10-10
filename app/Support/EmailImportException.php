<?php

namespace App\Support;

use RuntimeException;

/**
 * ไฟล์นำเข้าอีเมลใช้ไม่ได้ทั้งไฟล์ (ข้อความเป็นภาษาไทย แสดงให้ผู้ใช้เห็นได้เลย)
 */
class EmailImportException extends RuntimeException
{
}
