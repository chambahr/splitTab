<?php
namespace App\Enums;
enum AllocationMethod: string { case EQUAL = 'equal'; case PERCENTAGE = 'percentage'; case SHARES = 'shares'; case MANUAL = 'manual'; }
