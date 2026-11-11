<?php
namespace App\Enums;
enum TripRole: string { case OWNER = 'owner'; case ADMIN = 'admin'; case MEMBER = 'member'; case VIEWER = 'viewer'; }
