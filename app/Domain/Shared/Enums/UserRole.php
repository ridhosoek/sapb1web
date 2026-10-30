<?php

namespace App\Domain\Shared\Enums;

enum UserRole: string
{
    case AdminOperational = 'POOL_USER';
    case ManagerOperational = 'SUPERVISOR';
    case AdminHo = 'ADMIN_HO';
    case ManagerAdminHo = 'MANAGER_ADMIN_HO';
    case SuperAdmin = 'ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::AdminOperational => 'Admin Operasional',
            self::ManagerOperational => 'Manager Operasional / Transport',
            self::AdminHo => 'Admin HO',
            self::ManagerAdminHo => 'Manager Admin HO',
            self::SuperAdmin => 'Super Admin',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AdminOperational => 'Membuat dan melihat transaksi operasional untuk Warehouse yang ditentukan.',
            self::ManagerOperational => 'Melihat transaksi Warehouse-nya dan menyetujui Purchase Request melalui email.',
            self::AdminHo => 'Melihat PR dan GRPO, menyetujui Goods Issue, serta mendaftarkan user operasional.',
            self::ManagerAdminHo => 'Memonitor dokumen dan transaksi seluruh Warehouse serta dapat menjadi approval PR tingkat HO.',
            self::SuperAdmin => 'Akses penuh ke transaksi, konfigurasi, integrasi, approval, dan administrasi user.',
        };
    }

    public function canCreateOperationalDocuments(): bool
    {
        return in_array($this, [self::AdminOperational, self::SuperAdmin], true);
    }

    public function canViewAllDocuments(): bool
    {
        return in_array($this, [self::AdminHo, self::ManagerAdminHo, self::SuperAdmin], true);
    }

    public function canManageUsers(): bool
    {
        return in_array($this, [self::AdminHo, self::SuperAdmin], true);
    }

    public function canManageApprovalWorkflows(): bool
    {
        return $this === self::SuperAdmin;
    }

    public function canApprovePurchaseRequests(): bool
    {
        return in_array($this, [self::ManagerOperational, self::ManagerAdminHo, self::SuperAdmin], true);
    }

    public function canApproveGoodsIssues(): bool
    {
        return in_array($this, [self::AdminHo, self::SuperAdmin], true);
    }

    /** @return array<int, self> */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::SuperAdmin => self::cases(),
            self::AdminHo => [self::AdminOperational, self::ManagerOperational],
            default => [],
        };
    }
}
