<?php

namespace App\Support;

/**
 * ผลวิเคราะห์การนำเข้าอีเมล
 *
 * toUpdate / unchanged: แถวที่จะอัปเดต / อีเมลตรงกับที่มีอยู่แล้ว (row, user_id, username, email)
 * skipped: แถวที่ข้ามพร้อมเหตุผล (row, username, email, reason)
 */
class EmailImportResult
{
    /** @var list<array{row:int,user_id:int,username:string,email:string}> */
    public array $toUpdate = [];

    /** @var list<array{row:int,user_id:int,username:string,email:string}> */
    public array $unchanged = [];

    /** @var list<array{row:int,username:string,email:string,reason:string}> */
    public array $skipped = [];
}
