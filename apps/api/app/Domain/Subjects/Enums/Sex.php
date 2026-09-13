<?php

declare(strict_types=1);

namespace App\Domain\Subjects\Enums;

/** What a CNP's leading digit says about the person's recorded sex. */
enum Sex: string
{
    case MALE = 'MALE';
    case FEMALE = 'FEMALE';
}
