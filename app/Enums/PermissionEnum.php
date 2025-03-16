<?php

namespace App\Enums;

enum PermissionEnum
{
    // สำหรับเมนู User
    const USER_VIEW = 'user.view';
    const USER_ADD = 'user.add';
    const USER_EDIT = 'user.edit';
    const USER_DELETE = 'user.delete';

    // สำหรับเมนู Document
    const DOCUMENT_VIEW = 'document.view';
    const DOCUMENT_ADD = 'document.add';
    const DOCUMENT_EDIT = 'document.edit';
    const DOCUMENT_DELETE = 'document.delete';

    // สำหรับเมนู Task
    const TASK_VIEW = 'task.view';
    const TASK_ADD = 'task.add';
    const TASK_EDIT = 'task.edit';
    const TASK_DELETE = 'task.delete';

    // สำหรับเมนู Category
    const CATEGORY_VIEW = 'category.view';
    const CATEGORY_ADD = 'category.add';
    const CATEGORY_EDIT = 'category.edit';
    const CATEGORY_DELETE = 'category.delete';

    // สำหรับเมนู Section
    const SECTION_VIEW = 'section.view';
    const SECTION_ADD = 'section.add';
    const SECTION_EDIT = 'section.edit';
    const SECTION_DELETE = 'section.delete';
}