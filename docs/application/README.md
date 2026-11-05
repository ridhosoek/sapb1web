# Dokumentasi SAP B1 Operational Web Portal

Dokumentasi ini menjelaskan kondisi aplikasi berdasarkan implementasi aktual, bukan hanya requirement awal.

## Daftar dokumen

1. [Arsitektur dan integrasi](architecture.md) — komponen, data, SAP Service Layer, reliability, dan security.
2. [Design system](design-system.md) — token, tipografi, layout, komponen, state, dan cetakan.
3. [Folder link tree](folder-tree.md) — navigasi source code beserta tanggung jawab setiap folder.
4. [Bisnis proses](business-processes.md) — alur PR, progress, GRPO, Goods Issue, approval, dan report.
5. [Aturan operasional](operational-rules.md) — hal yang boleh, tidak boleh, dan matriks role.
6. [Runbook](runbook.md) — setup, environment, scheduler, deployment, test, dan troubleshooting.
7. [Context agent](../agent-handoff/context.md) — keputusan historis dan kondisi handoff untuk pengembangan berikutnya.

## Scope aplikasi

Portal menyediakan:

- login dengan pemilihan database SAP;
- Purchase Request dengan approval email bertingkat;
- pemantauan Purchase Quotation, Purchase Order, dan GRPO;
- Goods Receipt PO dari PO terbuka;
- Goods Issue dengan approval internal Admin HO;
- pencarian Item Master;
- Report Beban Sparepart;
- pengelolaan user dan workflow;
- audit/integration monitor;
- cetakan dokumen dan QR verifikasi.

SAP Business One tetap menjadi system of record. MySQL portal menyimpan akun, konteks operasional, workflow, cache master data, snapshot dokumen, attachment privat, audit, dan state integrasi.
