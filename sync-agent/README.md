# RxCore Sync Agent — Windows Client (Phase 3.1 – Phase 3.6.1)

เอกสารกำกับและคู่มือการใช้งาน **RxCore Sync Agent** สำหรับติดตั้งและใช้งานบนเครื่องคอมพิวเตอร์ของ รพ.สต. ในระบบปฏิบัติการ Windows ครอบคลุม:
- **Phase 3.1 Foundation:** สถาปัตยกรรมตัวแทนส่งข้อมูล, การตั้งค่าความปลอดภัย AES-256-GCM AEAD, การตรวจสิทธิ์ Read-only 5 ระดับ
- **Phase 3.2 Read-only Data Extraction:** โมดูลอ่านและตรวจสอบข้อมูลการจ่ายยา, คัดกรองทะเบียนยา, ป้องกันข้อมูลซ้ำ, และหน้าจอ Preview
- **Phase 3.2.1 Compatibility Test:** ตรวจสอบความเข้ากันได้ของคำสั่ง SQL และการบังคับใช้สิทธิ์ Read-only กับระบบจัดการฐานข้อมูล MySQL / MariaDB จริง
- **Phase 3.3 Sync Agent Core:** แกนประมวลผล Initial Sync (ย้อนหลัง 1 ปี) และ Incremental Sync (Mock Mode)
- **Phase 3.4 HOSxP Real Database Read Test:** โมดูลอ่านข้อมูล HOSxP จริงภายใต้สิทธิ์ Read-only และการยืนยันตัวตนของผู้ดูแลระบบ
- **Phase 3.5 Supabase API Integration (Mock Endpoint Only):** โมดูล API Client และ Mock Server จำลองการเชื่อมต่อ RESTful RPC
- **Phase 3.5.1 API Contract & Security Audit:** ตรวจสอบความตรงกันของ API Contract, Schema, RPC และทดสอบความปลอดภัย 15 สถานการณ์ Multi-tenant Isolation
- **Phase 3.6 Scheduler, Retry & Reconciliation (Mock Mode):** กลไกจัดตาราง Sync รอบเวลาประเทศไทย (09:00, 12:00, 16:00 น.), Retry Exponential Backoff + Jitter, และการกระทบยอดข้อมูล 30 วัน (30-Day Reconciliation Engine) แบบ Composite Key และ Preview Only
- **Phase 3.6.1 Reliability & Performance Hardening:** ระบบบันทึก Atomic State File บน Windows ปลอดภัยต่อไฟดับ/Crash, Retry Policy รองรับ `Retry-After` ทั้งวินาทีและ HTTP-date พร้อมเพดานเวลารอ, และ Performance Benchmark บนชุดข้อมูล 1K, 10K, 50K แถว

---

## 📌 สรุปเป้าหมายและการส่งมอบแต่ละ Phase

### 1. Phase 3.1 Foundation (Revision 2)
- **UI & Control Panel:** หน้าจอตั้งค่าการเชื่อมต่อ HOSxP MySQL (Host, Port, Database, Username, Password, HCODE)
- **Connection Diagnostics:** ตรวจสอบสถานะการเชื่อมต่อ, วัด Latency, ตรวจสอบเวอร์ชัน MySQL, สิทธิ์ Read-only (SELECT) และตารางสำคัญ 5 ตารางของ HOSxP
- **Security & Privacy:** รหัสผ่านถูกเข้ารหัสด้วย **AES-256-GCM (AEAD)** พร้อม Authentication Tag 16 ไบต์ และรองรับ Backward Compatibility สำหรับ CBC เดิม
- **Multi-Level Privilege Audit:** ตรวจสอบสิทธิ์จริงของ MySQL ครอบคลุมทั้ง Global, Schema, Table, Column และ Role โดยไม่ตัดสินจากชื่อ Username

### 2. Phase 3.2 Read-only Data Extraction
- **Read-only SQL Extractor:** โมดูลดึงข้อมูลการจ่ายยาจากตาราง `opitemrece r`, `drugitems d`, และ `vn_stat v`
- **PDO-Safe Dynamic Binding:** สร้าง Named Placeholders (`:drug_0, :drug_1, ...`) สำหรับเงื่อนไข `d.tpu_code_list IN (...)` ป้องกัน SQL Injection 100% โดยไม่นำค่าผู้ใช้มาต่อสตริง
- **Drug Master Registry Filter:** เลือกเฉพาะรหัสยาที่อยู่ในทะเบียนยาที่กำหนด (Mock Configuration 10 รายการ) โดยยาที่ไม่มีในทะเบียนจะถูกข้ามเงียบๆ โดยไม่บันทึก Log
- **Validation Pipeline:** ตรวจสอบ `hos_guid` ไม่ว่าง, `qty > 0`, `vn` และ `hcode` (5 หลัก) ถูกต้อง
- **Composite Key Deduplication:** ป้องกันรายการซ้ำด้วย Composite Key `(hcode, hos_guid)` โดยอนุญาตให้ `hos_guid` เดียวกันแต่คนละ `hcode` ผ่านได้
- **Strict Data Isolation (No PII):** ไม่ดึง HN, CID, หรือชื่อผู้ป่วย ส่งออกเฉพาะ 6 ฟิลด์ที่กำหนดอย่างเคร่งครัด: `hos_guid`, `vn`, `hcode`, `vstdate`, `tpu_code_list`, `qty`
- **Data Extraction Preview UI:** หน้าจอพรีวิวก่อน Sync แสดงช่วงวันที่, การนับสถิติ 4 ตัวชี้วัด (อ่านได้, ผ่าน, ตัดซ้ำ, ผิดรูปแบบ), การแจกแจงข้อผิดพลาด, และตารางข้อมูลตัวอย่าง

### 3. Phase 3.2.1 Compatibility Test
- **Real MySQL / MariaDB Environment:** ทดสอบบน MariaDB 10.4.32 ผ่านฐานข้อมูลทดสอบ `rxcore_hosxp_test`
- **Synthetic Test Data (Zero Patient Data):** ใช้ข้อมูลสังเคราะห์ 100% เลียนแบบโครงสร้างสกีมาจริงของ HOSxP โดยไม่มีข้อมูลเวชระเบียนหรือข้อมูลผู้ป่วยจริง
- **SQL Compatibility Verification:** ยืนยันความเข้ากันได้ของคำสั่ง SQL, การผูก Named Placeholders, การเปรียบเทียบฟิลด์ประเภท DATE, และการเรียงลำดับ ORDER BY บน MySQL Engine
- **Read-only Enforcement & Attack Surface Test:** ทดสอบคำสั่งแก้ไขข้อมูล (`INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`, `CREATE`) ภายใต้บัญชี Read-only `rxcore_ro_test` ยืนยันว่าถูกบล็อกด้วย MySQL Error 1142 (Access Denied) ทั้งหมด 100%
- **Query Plan (EXPLAIN) Analysis:** วิเคราะห์ Execution Plan ของ MySQL ยืนยันการใช้งาน Index บนตาราง `opitemrece`, `drugitems`, และ `vn_stat`
- **Zero Cloud Transmission:** ไม่มีการเชื่อมต่อ Supabase หรือส่งข้อมูลออกนอกเครื่องคอมพิวเตอร์

### 4. Phase 3.3 Sync Agent Core (Mock Mode)
- **Initial Sync (ย้อนหลัง 1 ปี):** คำนวณช่วงวันย้อนหลัง 1 ปีอัตโนมัติ กรองเฉพาะยาในทะเบียน และคัดกรองข้อมูลอย่างถูกต้อง
- **Incremental Sync:** ใช้วันที่ล่าสุดของข้อมูลเดิมเป็น Cursor สำหรับดึงข้อมูลที่เพิ่มเข้ามาใหม่
- **Idempotence & Composite Key:** ป้องกันการส่งข้อมูลซ้ำ ไม่สร้างแถวเบิ้ลในฐานข้อมูล
- **Local State Management:** บันทึก State การ Sync ในไฟล์ JSON ภายในเครื่อง

### 5. Phase 3.4 HOSxP Real Database Read Test
- **Test A (Connection Diagnostics):** ตรวจสอบเครือข่ายและ Handshake, ปฏิเสธบัญชี Admin (`root`, `admin`, `sa`) ทันที
- **Test B (Schema Validation):** ตรวจสอบโครงสร้างตาราง `opitemrece`, `drugitems`, `vn_stat` ครบถ้วนตามสเปก
- **Test C (Read-only Privilege Audit):** ตรวจสอบสิทธิ์ 5 ระดับ อนุญาตเฉพาะ SELECT เท่านั้น
- **Test D (Query with Admin Confirmation):** บังคับจำกัดการอ่านไม่เกิน 1 วัน ต้องกดยืนยันก่อนอ่าน และจำกัดผลลัพธ์ตัวอย่างสูงสุด 100 รายการ
- **Test E (Result Comparison):** เครื่องมือเปรียบเทียบความตรงกันของตัวเลขนับระหว่าง HOSxP และ Agent

### 6. Phase 3.5 Supabase API Integration (Mock Endpoint Only)
- **AgentApiClient:** พัฒนาตัวส่งข้อมูล Batch JSON ไปยัง RESTful API RPC Endpoint
- **AgentMockApiServer:** จำลองพฤติกรรม Supabase RPC `fn_sync_dispense_batch` ครบ 13 สถานการณ์ (HTTP 200, 400, 401, 403, 409, 429, 500, Timeout, Malformed JSON ฯลฯ)
- **PII Guard:** สกัดกั้นฟิลด์เกินและข้อมูล PII (HN, CID, ชื่อ) ตั้งแต่ระดับ Pre-flight ก่อนออกสู่อินเทอร์เน็ต

### 7. Phase 3.5.1 API Contract & Security Audit
- **Contract & Schema Alignment:** ตรวจสอบความสอดคล้อง 100% ระหว่าง API Client, Mock Server และ `database/schema.sql` (ตาราง `rx_dispense`, `rx_health_centers`, RPC `fn_sync_dispense_batch`)
- **Multi-tenant Isolation:** บังคับใช้ API Key เป็นแหล่งอ้างอิงสถานพยาบาลหลัก (Source of Truth) ห้ามเชื่อถือ `hcode` จาก Client โดยตรง ปฏิเสธการสวมสิทธิ์ข้ามสถานพยาบาลด้วย HTTP 403 `FORBIDDEN_CROSS_TENANT`
- **Security Audit Test Suite:** ทดสอบความปลอดภัยครอบคลุม 15 กรณีทดสอบ (72 การตรวจสอบ ผ่าน 100%)

### 8. Phase 3.6 Scheduler, Retry & Reconciliation (Mock Mode)
- **AgentMockClock:** โมดูลนาฬิกาจำลอง รองรับไทม์โซน `Asia/Bangkok` (GMT+7) ควบคุมเวลาแบบ Virtual Time โดยไม่ต้องรอเวลาจริง
- **AgentMockTransport:** ตัวกลางจำลองการสื่อสาร HTTP In-memory บันทึก Timeline และ Sanitized Request History ปลอดการส่งสัญญาณออกนอกเครื่อง 100%
- **AgentScheduler:** ระบบจัดตาราง Sync อัตโนมัติ 3 รอบต่อวัน (09:00, 12:00, 16:00 น.) ตรวจสอบกรอบเวลา ป้องกันการรันซ้ำในรอบเดียวกันด้วย Slot Key (`YYYY-MM-DD@HH:MM`) และตรวจจับรอบที่พลาด (Missed Slots) โดยไม่รันซ้อนทับกัน
- **AgentRetryPolicy:** นโยบาย Retry สำหรับข้อผิดพลาดชั่วคราว (Transient: Timeout, Connection Error, HTTP 429, HTTP 5xx) ด้วย **Exponential Backoff + 15% Jitter** เคารพ Header `Retry-After` และหยุดทันทีเมื่อพบข้อผิดพลาดถาวร (Permanent: HTTP 400, 401, 403)
- **AgentReconciliation:** กลไกกระทบยอดข้อมูลย้อนหลัง 30 วัน (Reconciliation Preview) เปรียบเทียบ Composite Key `(hcode, hos_guid)` จำแนกเป็น 4 กลุ่ม: `NEW`, `CHANGED`, `SOFT_DELETE_CANDIDATE`, `REACTIVATION_CANDIDATE` ภายใต้การตรวจสอบ **Source Completeness** อย่างเคร่งครัด หากดึงข้อมูลไม่สมบูรณ์จะยกเลิกการกระทบยอดทันทีเพื่อป้องกันข้อมูลสูญหาย

### 9. Phase 3.6.1 Reliability & Performance Hardening
- **AgentAtomicFile:** เครื่องมือบันทึกไฟล์ State แบบ Atomic Replacement บนระบบปฏิบัติการ Windows:
  - เขียนลงไฟล์ชั่วคราวในไดเรกทอรีเดียวกัน (รับประกันอยู่ใน Filesystem เดียวกัน)
  - อ่านกลับและทดสอบ `json_decode()` เพื่อยืนยันความสมบูรณ์ก่อนสลับไฟล์
  - ป้องกันการเขียนพร้อมกันด้วย Process Lock `flock(LOCK_EX)`
  - สลับไฟล์ด้วยเทคนิค Windows-safe Backup & Rollback หากเกิดความผิดพลาด ไฟล์เดิมจะคงสภาพเดิม 100%
  - ผ่านการทดสอบ Fault Injection ครอบคลุม: ไฟล์หาย, ไฟล์เสียหาย, พื้นที่ไม่พอ (Disk Full), และโปรแกรมหยุดทำงานกะทันหัน
- **Retry Policy Hardening:**
  - รองรับ Header `Retry-After` ทั้งรูปแบบจำนวนวินาที (`5`, `"12"`) และรูปแบบ **HTTP-date** ตามมาตรฐาน RFC 7231 / RFC 1123 (เช่น `Wed, 28 Oct 2026 10:00:15 GMT`)
  - บังคับใช้เพดานเวลารอสูงสุด (**Max Delay Ceiling**) เพื่อป้องกันไม่ให้รอเกินนโยบาย แม้เซิร์ฟเวอร์จะส่งเวลารอไกลข้ามวันมาก็ตาม
  - คงนโยบายไม่ Retry ข้อผิดพลาดถาวร (HTTP 400, 401, 403) และป้องกันข้อมูลรั่วไหลใน Timeline
- **Reconciliation Benchmark (1K, 10K, 50K):**
  - ทดสอบวัดประสิทธิภาพจริงด้วย Synthetic Dataset ในหน่วยความจำ (Zero Patient Data)
  - ประมวลผล 50,000 รายการในเวลาเพียง **68.79 ms** ด้วย Peak Memory **88 MB** (Throughput กว่า 720,000 แถว/วินาที)
  - ยืนยันความถูกต้องของ Composite Key `(hcode, hos_guid)` 100% และตรวจจับข้อผิดพลาดคิวรีหลุดกลางคันได้ภายใน **0.03 ms** โดยไม่สร้างรายการลบผิดพลาดแม้แต่แถวเดียว

---

## 📂 โครงสร้างไฟล์ในโฟลเดอร์ `sync-agent/`

```text
sync-agent/
├── index.php                     # หน้าจอหลัก (Navigation Tabs: Connection, Preview, Real Read Test, API Test, Phase 3.6 Tab)
├── agent_config.php              # โมดูลจัดการ Config (AES-256-GCM AEAD, Tag, Backward Compatibility)
├── agent_connection.php          # โมดูลทดสอบการเชื่อมต่อ MySQL (ตรวจสิทธิ์ 5 ระดับ Global/Schema/Table/Col/Role)
├── agent_drug_registry.php       # Mock Drug Master Registry (10 รายการรหัส TPU/TMT)
├── mock_dataset.php              # Mock Dataset & In-memory SQLite PDO Builder (10 กรณีทดสอบ)
├── agent_extractor.php           # Data Extractor & Validation Pipeline (PDO-Safe Query)
├── agent_mock_clock.php          # Virtual Mock Clock (Asia/Bangkok Timezone)
├── agent_mock_transport.php      # Zero-network Mock HTTP Transport
├── agent_mock_state.php          # Mock Sync State (อัปเกรดใช้งาน AgentAtomicFile)
├── agent_atomic_file.php         # [ใหม่ Phase 3.6.1] Crash-Resilient Windows-Safe Atomic File Engine
├── agent_scheduler.php           # Scheduler Engine (09:00, 12:00, 16:00 Slots & Missed Detection)
├── agent_retry_policy.php        # [ปรับปรุง Phase 3.6.1] Retry Policy (HTTP-date & Max Delay Ceiling)
├── agent_reconciliation.php      # 30-Day Reconciliation Engine (Strict Completeness Check)
├── benchmark_reconciliation.php  # [ใหม่ Phase 3.6.1] Reconciliation Performance Benchmark (1K, 10K, 50K)
├── compat_setup_mysql.php        # ตัวติดตั้งฐานข้อมูลทดสอบ rxcore_hosxp_test และ User Read-only บน MySQL
├── test_mysql_compatibility.php  # ชุดทดสอบความเข้ากันได้ของ SQL และสิทธิ์ Read-only บน MySQL (37 ผ่าน 100%)
├── test_agent.php                # ชุดทดสอบอัตโนมัติ Phase 3.1 (56 การตรวจสอบ ผ่าน 100%)
├── test_extractor.php            # ชุดทดสอบอัตโนมัติ Phase 3.2 (27 การตรวจสอบ ผ่าน 100%)
├── test_sync_core.php            # ชุดทดสอบอัตโนมัติ Phase 3.3 (46 การตรวจสอบ ผ่าน 100%)
├── test_hosxp_reader.php         # ชุดทดสอบอัตโนมัติ Phase 3.4 (66 การตรวจสอบ ผ่าน 100%)
├── test_api_client.php           # ชุดทดสอบอัตโนมัติ Phase 3.5 (59 การตรวจสอบ ผ่าน 100%)
├── test_security_audit.php       # ชุดทดสอบความปลอดภัย & Multi-tenant Phase 3.5.1 (72 การตรวจสอบ ผ่าน 100%)
├── test_phase36.php              # ชุดทดสอบ Scheduler, Retry & Reconciliation Phase 3.6 (63 การตรวจสอบ ผ่าน 100%)
├── test_phase361.php             # [ใหม่ Phase 3.6.1] ชุดทดสอบ Reliability & Hardening (61 การตรวจสอบ ผ่าน 100%)
├── agent_api_client.php          # API Client (Payload Limits, TLS Enforce, Audit Helper)
├── agent_mock_server.php         # Server Mock & Multi-tenant Isolation Engine
├── agent_hosxp_reader.php        # โมดูลทดสอบอ่าน HOSxP จริง 5 ขั้นตอน (Test A-E)
├── agent_sync_core.php           # Sync Core Processor (Initial / Incremental Sync)
├── agent_api.php                 # AJAX API Endpoint (รองรับ Phase 3.1 - Phase 3.6.1)
├── agent_cli.php                 # CLI Runner สำหรับ Windows Task Scheduler / Background Worker
├── run_agent.bat                 # Windows Batch Launcher สำหรับเปิดหน้า Web UI ทันที
├── run_cli_test.bat              # Windows Batch สำหรับทดสอบ Connection Diagnostics ผ่าน Command Prompt
├── README.md                     # เอกสารส่งมอบและคู่มือการทดสอบฉบับนี้
├── assets/
│   ├── agent.css                 # Stylesheet (UI Tabs, KPI Cards, Preview Table, Log Console, Phase 3.6 Badges)
│   └── agent.js                  # JavaScript ควบคุมฟอร์ม, AJAX, KPI Cards, Preview Table, Phase 3.6 Controllers
├── config/                       # โฟลเดอร์เก็บไฟล์ตั้งค่าเข้ารหัส (ป้องกันด้วย .htaccess)
│   ├── .htaccess                 # ป้องกันการเข้าถึงไฟล์ตรงจากภายนอก (Deny from all)
│   └── agent_settings.enc.json   # ค่าตั้งค่าการเชื่อมต่อ (AES-256-GCM)
└── logs/                         # โฟลเดอร์เก็บไฟล์ Audit Logs (ป้องกันด้วย .htaccess)
    ├── .htaccess                 # ป้องกันการเข้าถึงไฟล์ตรงจากภายนอก (Deny from all)
    └── agent_YYYY-MM-DD.log      # ไฟล์บันทึกเหตุการณ์ (Masked Credentials & No PII)
```

---

## 🚀 ผลการทดสอบประสิทธิภาพ Reconciliation Benchmark (Phase 3.6.1)

รันคำสั่งจริง: `& "C:\xampp\php\php.exe" c:\xampp\htdocs\rxcore\sync-agent\benchmark_reconciliation.php`

| สเกลทดสอบ (Scale) | จำนวนแถว (Source / Target) | เวลาประมวลผล (Time) | หน่วยความจำสูงสุด (Peak Memory) | อัตราประมวลผล (Throughput) | ความถูกต้อง Composite Key | ผลการทดสอบ |
|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **1,000 รายการ (1K)** | 950 / 900 แถว | **0.73 ms** | 4.00 MB | 1,367,428 แถว/วินาที | ตรวจสอบผ่าน 100% | **PASS** |
| **10,000 รายการ (10K)** | 9,500 / 9,000 แถว | **8.47 ms** | 18.00 MB | 1,181,195 แถว/วินาที | ตรวจสอบผ่าน 100% | **PASS** |
| **50,000 รายการ (50K)** | 47,500 / 45,000 แถว | **68.79 ms** | 88.00 MB | 726,873 แถว/วินาที | ตรวจสอบผ่าน 100% | **PASS** |

### รายละเอียดการจำแนกหมวดหมู่ของชุดข้อมูลสังเคราะห์ 50,000 รายการ
- **รายการใหม่ (NEW):** 5,000 รายการ (ตรงตามสัดส่วน 10%)
- **รายการเปลี่ยนแปลง (CHANGED):** 5,000 รายการ (ตรงตามสัดส่วน 10%)
- **รายการคงเดิม (UNCHANGED):** 35,000 รายการ (ตรงตามสัดส่วน 70%)
- **คาดว่าจะถูกลบ (SOFT_DELETE_CANDIDATE):** 2,500 รายการ (ตรงตามสัดส่วน 5%)
- **รายการกลับมาพบ (REACTIVATION_CANDIDATE):** 2,500 รายการ (ตรงตามสัดส่วน 5%)

### การทดสอบความปลอดภัย Failsafe Protection (10,000 แถว)
- **เวลาสั่งยกเลิกฉับพลันเมื่อ Query Timeout:** **0.03 ms**
- **สถานะการทำงาน:** `FAILED` (ยกเลิกกระบวนการทันที)
- **การันตี Zero False Deletes:** **0 รายการ** (ไม่มีการสร้าง Soft Delete Candidate แม้แต่แถวเดียว เมื่อคิวรีต้นทางมีปัญหา)

---

## 🧪 สรุปผลการรัน Automated Test Suites ทั้งหมดของโครงการ (487/487 ผ่าน 100%)

```powershell
# Phase 3.1: Foundation, AES-256-GCM AEAD, 5-Level MySQL Privileges
& "C:\xampp\php\php.exe" c:\xampp\htdocs\rxcore\sync-agent\test_agent.php
# ผลลัพธ์: 56/56 PASSED (100%)

# Phase 3.2: Read-only Data Extraction & Validation Pipeline
& "C:\xampp\php\php.exe" c:\xampp\htdocs\rxcore\sync-agent\test_extractor.php
# ผลลัพธ์: 27/27 PASSED (100%)

# Phase 3.2.1: MySQL/MariaDB Compatibility Test
& "C:\xampp\php\php.exe" c:\xampp\htdocs\rxcore\sync-agent\test_mysql_compatibility.php
# ผลลัพธ์: 37/37 PASSED (100%)

# Phase 3.3: Sync Agent Core (Initial & Incremental Sync Mock)
& "C:\xampp\php\php.exe" c:\xampp\htdocs\rxcore\sync-agent\test_sync_core.php
# ผลลัพธ์: 46/46 PASSED (100%)

# Phase 3.4: HOSxP Real Database Read Test (Test A-E)
& "C:\xampp\php\php.exe" c:\xampp\htdocs\rxcore\sync-agent\test_hosxp_reader.php
# ผลลัพธ์: 66/66 PASSED (100%)

# Phase 3.5: Supabase API Integration (Mock Endpoint Only)
& "C:\xampp\php\php.exe" c:\xampp\htdocs\rxcore\sync-agent\test_api_client.php
# ผลลัพธ์: 59/59 PASSED (100%)

# Phase 3.5.1: API Contract & Security Audit
& "C:\xampp\php\php.exe" c:\xampp\htdocs\rxcore\sync-agent\test_security_audit.php
# ผลลัพธ์: 72/72 PASSED (100%)

# Phase 3.6: Scheduler, Retry Policy & 30-Day Reconciliation (Mock Mode)
& "C:\xampp\php\php.exe" c:\xampp\htdocs\rxcore\sync-agent\test_phase36.php
# ผลลัพธ์: 63/63 PASSED (100%)

# Phase 3.6.1: Reliability & Performance Hardening
& "C:\xampp\php\php.exe" c:\xampp\htdocs\rxcore\sync-agent\test_phase361.php
# ผลลัพธ์: 61/61 PASSED (100%)

# Phase 3.7: MOPH Notify & Sync Status Monitoring (Mock Mode)
& "C:\xampp\php\php.exe" c:\xampp\htdocs\rxcore\sync-agent\test_phase37.php
# ผลลัพธ์: 114/114 PASSED (100%)

# Phase 2 Database Schema Integrity Verification
& "C:\xampp\php\php.exe" c:\xampp\htdocs\rxcore\database\validate_schema.php
# ผลลัพธ์: ALL SCHEMA & TEST SUITE CHECKS PASSED (100%)
```

| ลำดับ | ชุดทดสอบ | รายละเอียดโมดูล | จำนวนข้อสอบ | ผลลัพธ์ |
|:---:|---|---|:---:|:---:|
| 1 | `test_agent.php` | Phase 3.1: Foundation, AES-256-GCM AEAD, 5-Level MySQL Privileges | 56 | **PASS (100%)** |
| 2 | `test_extractor.php` | Phase 3.2: Read-only Extractor, 6 Fields Whitelist, Key Deduplication | 27 | **PASS (100%)** |
| 3 | `test_mysql_compatibility.php` | Phase 3.2.1: Compatibility Test with MariaDB/MySQL & Read-only User | 37 | **PASS (100%)** |
| 4 | `test_sync_core.php` | Phase 3.3: Sync Agent Core, Initial & Incremental Sync (Mock Mode) | 46 | **PASS (100%)** |
| 5 | `test_hosxp_reader.php` | Phase 3.4: HOSxP Real Database Read Test (5 Test Suites A, B, C, D, E) | 66 | **PASS (100%)** |
| 6 | `test_api_client.php` | Phase 3.5: Supabase API Integration (Mock Endpoint Only, 13 Scenarios) | 59 | **PASS (100%)** |
| 7 | `test_security_audit.php` | Phase 3.5.1: API Contract & Security Audit (15 Security Scenarios) | 72 | **PASS (100%)** |
| 8 | `test_phase36.php` | Phase 3.6: Scheduler, Retry Policy & 30-Day Reconciliation (15 Scenarios) | 63 | **PASS (100%)** |
| 9 | `test_phase361.php` | Phase 3.6.1: Reliability Hardening, Atomic File & Benchmark (8 Groups) | 61 | **PASS (100%)** |
| 10 | `test_phase37.php` | Phase 3.7: MOPH Notify & Sync Status Monitoring (16 Scenarios) | 114 | **PASS (100%)** |
| 11 | `test_packaging.php` | Phase 3.8.1: Packaging & Windows Deployment Readiness (10 Groups) | 38 | **PASS (100%)** |
| 12 | `test_isolated_deployment.php` | Phase 3.8.2: Isolated Windows Deployment & Recovery (12 Tests + Recovery) | 47 | **PASS (46/46, 1 NOT TESTED)** |
| | **รวมทั้งสิ้น** | **ครอบคลุมทุกโมดูลของ Phase 3 (Foundation ถึง Phase 3.8.2)** | **686** | **PASS 100% (685/685, 1 NOT TESTED)** |

---

## 📦 Phase 3.8.1: Windows Agent Packaging & Deployment Readiness

### 1. วัตถุประสงค์และการแยกสถาปัตยกรรม (Architecture Separation)
Phase 3.8.1 เตรียมความพร้อมของ RxCore Sync Agent สำหรับการติดตั้งบนเครื่องคอมพิวเตอร์ Windows ของ รพ.สต. ทั้ง 6 แห่ง ภายใต้โหมดทดสอบความปลอดภัยสูง (Strict Mock Mode):
- **Decoupled Service Host & Web UI:** แยก `AgentServiceHost` (`agent_service_host.php`) ซึ่งทำหน้าที่เป็น Background CLI Worker ออกจาก Web UI (`index.php` และ `agent_api.php`) อย่างสิ้นเชิง หากส่วนหนึ่งส่วนใดขัดข้องจะไม่ส่งผลกระทบต่ออีกส่วนหนึ่ง
- **Single Instance Process Mutex:** `AgentLifecycle` (`agent_lifecycle.php`) จัดการ Process Mutex ผ่าน Lock File (`config/agent_service.lock`) เก็บ PID, Timestamp, Heartbeat, Memory Usage ป้องกันการเปิดโปรแกรมซ้อนทับ พร้อมกลไกตรวจจับ Zombie / Dead Process และกู้คืน Lock อัตโนมัติ
- **Graceful Shutdown Signaling:** รองรับการสั่งปิดโปรแกรมผ่าน Token File (`config/agent_service.signal`) โดยให้งาน Mock Sync ในรอบปัจจุบันเสร็จสิ้นอย่างสมบูรณ์ก่อนปลด Lock และหยุดโปรแกรม
- **Automated Windows Environment Auditor:** `AgentEnvironmentChecker` (`agent_env_check.php`) ตรวจสอบเวอร์ชัน PHP, Extensions, ระบบปฏิบัติการ, สิทธิ์การเขียนโฟลเดอร์ `config/` และ `logs/`, และความเข้ากันได้กับ Path ภาษาไทยและ Path ที่มีช่องว่าง

### 2. มาตรการความปลอดภัยและ Credential Protection
- **ห้ามฝัง Credential ในแพ็กเกจ (Zero Plaintext Secrets):** แพ็กเกจที่สร้างใน `dist/rxcore-sync-agent/` จะไม่มีไฟล์ `agent_settings.json` หรือรหัสผ่านจากเครื่องพัฒนา มีเฉพาะ `agent_settings.example.json` ซึ่งเป็น Template เปล่า
- **AES-256-GCM AEAD Encryption:** รหัสผ่านการเชื่อมต่อจะถูกเข้ารหัสด้วย AES-256-GCM พร้อม Authentication Tag ยืนยันความถูกต้อง หาก Tag ถูกดัดแปลงแม้แต่ 1 บิต ระบบจะปฏิเสธการถอดรหัสทันที
- **Least Privilege Access:** ทุกโฟลเดอร์ภายใน `config/` และ `logs/` ถูกปกป้องด้วย `.htaccess` (`Deny from all`)
- **Strict Mock Invariant:** ห้ามเชื่อมต่อ HOSxP Production จริง ห้ามส่งข้อมูลไป Supabase Cloud และห้ามยิง MOPH Notify API จริงเด็ดขาดตลอด Phase 3.8.1

### 3. รายการ Dependencies และเวอร์ชันที่ทดสอบและรับรองจริง (Dependency Matrix)
*ห้ามระบุว่ารองรับเวอร์ชันใด หากยังไม่ได้ทดสอบจริง*
| องค์ประกอบ | เวอร์ชันที่ผ่านการทดสอบจริง | รายละเอียด / ความจำเป็น |
|---|---|---|
| **ระบบปฏิบัติการ** | Windows 10, Windows 11 (tested on Windows NT 10.0 build 26300) | สถาปัตยกรรม x64 |
| **PHP Runtime** | PHP 8.2.12 (x64 ZTS / NTS) | รองรับ PHP 8.1.0 ขึ้นไป |
| **PHP Extensions** | `openssl` | เข้ารหัส AES-256-GCM AEAD ป้องกันรหัสผ่าน |
| | `pdo` & `pdo_mysql` | การเชื่อมต่อฐานข้อมูล HOSxP MySQL / MariaDB แบบ Read-only |
| | `mbstring` | รองรับภาษาไทยและ UTF-8 Multibyte |
| | `json` | จัดการข้อมูล State, Events และ API Payloads |
| | `curl` | HTTP Client Transport (เตรียมพร้อมสำหรับ Production) |
| **Web Server** | PHP Built-in Server หรือ Apache (XAMPP) | สำหรับแสดงผล Local Web UI บนพอร์ต 8080 |

### 4. วิธีการ Build, ติดตั้ง, เริ่ม และหยุดโปรแกรม

#### การ Build Package:
```powershell
c:\xampp\php\php.exe sync-agent/build_package.php
```
โปรแกรมจะสร้างโฟลเดอร์ `dist/rxcore-sync-agent/` พร้อมตรวจสอบความปลอดภัยว่าไม่มี Secret หลุดออกไป

#### การตรวจสอบสภาพแวดล้อมก่อนติดตั้งบนเครื่อง รพ.สต.:
ดับเบิลคลิกไฟล์ `check_env.bat` หรือรันผ่าน CLI:
```powershell
php agent_service_host.php --check-env
```

#### การเริ่มทำงาน (Service Host Worker Loop):
ดับเบิลคลิกไฟล์ `start_agent.bat` หรือรันผ่าน CLI:
```powershell
php agent_service_host.php --start --interval=5
```

#### การตรวจสอบสถานะการทำงาน:
ดับเบิลคลิกไฟล์ `check_status.bat` หรือรันผ่าน CLI:
```powershell
php agent_service_host.php --status
```

#### การหยุดทำงานอย่างปลอดภัย (Graceful Shutdown):
ดับเบิลคลิกไฟล์ `stop_agent.bat` หรือรันผ่าน CLI:
```powershell
php agent_service_host.php --stop
```

#### การเปิดใช้งาน Local Web UI:
ดับเบิลคลิกไฟล์ `run_web_ui.bat` จากนั้นเข้าเว็บเบราว์เซอร์ที่ `http://127.0.0.1:8080/`

---

## 🧪 Phase 3.8.2: Isolated Windows Deployment Test

### 1. วัตถุประสงค์และการจำลองสภาพแวดล้อมแยก (Isolated Sandbox)
Phase 3.8.2 ดำเนินการทดสอบการติดตั้งและใช้งานแพ็กเกจ `dist/rxcore-sync-agent/` ที่สร้างจาก Phase 3.8.1 ในสภาพแวดล้อม Sandbox แยก (`dist/isolated_deployment_sandbox`) โดยมีการสำรองแพ็กเกจเดิมไว้เป็น Reference ที่ `dist/rxcore-sync-agent-phase381-ref/`:
- **สภาพแวดล้อมทดสอบจริง:** Windows NT 10.0 build 26300 (Windows 11 x64), PHP 8.2.12 (CLI & Built-in Server), Extensions: `openssl`, `pdo`, `pdo_mysql`, `mbstring`, `json`, `curl`
- **การทดสอบแบบ Sandbox แยก:** แตกแพ็กเกจไปยังโฟลเดอร์ทดสอบอิสระ ทำการคำนวณและตรวจสอบ Checksum SHA-256 ของทุกไฟล์เทียบกับ `package_manifest.json` ครบทั้ง 37 ไฟล์ (ตรงกัน 100%)
- **ความจริงใจต่อสภาพแวดล้อม (Honest Reporting):** การทดสอบทำบนเครื่องทดสอบ Windows เครื่องเดียวกันโดยแยกโฟลเดอร์ Sandbox อิสระ ไม่ได้ใช้เครื่องหรือ VM แยกต่างหาก และระบุการทดสอบ Reboot เครื่อง Windows จริงเป็น **NOT TESTED** เพื่อป้องกันผลกระทบต่อระบบปฏิบัติการของ Host

### 2. สรุปผลการทดสอบ Deployment (12 รายการ)
1. **แตกแพ็กเกจและตรวจ Checksum:** ผ่าน (37/37 ไฟล์มี SHA-256 ตรงตาม Manifest)
2. **รัน `check_env.bat`:** ผ่าน (PHP 8.2, Extensions 6 ตัวครบถ้วน, สิทธิ์อ่าน/เขียนผ่าน)
3. **รัน `start_agent.bat`:** ผ่าน (เริ่มทำงานใน Mock Mode, บันทึก PID และรัน Mock Cycle สำเร็จ)
4. **รัน `check_status.bat`:** ผ่าน (แสดง PID, Uptime, Heartbeat, และสถานะ Mock ชัดเจน)
5. **ป้องกันการเปิดซ้ำ:** ผ่าน (บล็อก Worker ตัวที่สองด้วย Exit Code 1 พร้อมแจ้งเตือน PID ที่เปิดอยู่)
6. **รัน `stop_agent.bat`:** ผ่าน (ส่ง Shutdown Signal, รอจบรอบอย่างปลอดภัย, ปลด Lock และปิดตัว)
7. **เริ่ม Agent ใหม่หลังหยุด:** ผ่าน (เริ่มทำงานรอบใหม่ได้ทันทีโดยไม่มีปัญหา Lock ค้าง)
8. **ทดสอบ Path ช่องว่างและภาษาไทย:** ผ่าน (`dist/rxcore isolated space/` และ `dist/ทดสอบ_ติดตั้ง_รพสต_บ้านดอน/`)
9. **Configuration หายหรือเสียหาย:** ผ่าน (ตรวจจับการ Tamper Ciphertext/Tag และคืนค่า Safe Mock Config โดยไม่ Crash)
10. **Log Directory เขียนไม่ได้:** ผ่าน (ดักจับข้อยกเว้น I/O ได้อย่างปลอดภัย และเก็บ Event ใน Memory Buffer แทน)
11. **ไม่บันทึก Credential ลง Log:** ผ่าน (ตรวจสอบไฟล์ State และ Log ไม่พบ Plaintext Passwords หรือ Secrets)
12. **ไม่มี Network Request ออกภายนอก:** ผ่าน (บังคับใช้ Mock Notify Adapter, Outbound Attempts = 0 ตลอดเวลา)

### 3. สรุปผลการทดสอบ Restart & Recovery (5 รายการ)
1. **Clean Restart:** ผ่าน (ปิด Worker ปกติแล้วเริ่มใหม่ได้ทันที)
2. **Crash Simulation & Stale Lock Auto-Recovery:** ผ่าน (จำลอง PID 99999999 ตายไปแล้ว ระบบตรวจพบ Dead PID และกู้คืน Lock ให้ Worker ตัวใหม่เข้าทำงานได้อัตโนมัติ)
3. **State File Persistence:** ผ่าน (`mock_sync_monitor_state.json` และ `mock_sync_events.json` ยังคงเป็น Valid JSON อ่านได้สมบูรณ์)
4. **Idempotent Slot Guard:** ผ่าน (บล็อกการรันซ้ำใน Slot เดิม `BLOCKED_DUPLICATE` ป้องกันข้อมูลซ้ำซ้อน)
5. **Windows Machine Reboot:** **NOT TESTED** (ไม่สามารถสั่ง Reboot เครื่องคอมพิวเตอร์ Host จริงได้)

---

## 🛡️ มาตรการความปลอดภัยและข้อจำกัดการทดสอบ (Boundaries & Limitations)

1. **Mock Mode Only & Zero Internet Transmission:** ใช้งานเฉพาะ Mock Clock, Mock Transport และ Synthetic Dataset ในหน่วยความจำ ไม่มีการส่ง HTTP Request ออกภายนอกเครื่องคอมพิวเตอร์ และไม่มีการเชื่อมต่อ Supabase หรือ MOPH Notify จริงเด็ดขาด
2. **ไม่แตะต้อง Phase 1 และ Phase 2:** โค้ดและสกีมาใน `database/schema.sql`, `database/test_schema.sql`, `api/index.php`, และหน้าเว็บหลักยังคงสภาพเดิม 100%
3. **ห้ามสร้าง Windows Service หรือ Scheduled Task จริงใน Phase นี้:** มีการเตรียม Service Host และ Batch Launchers แต่ห้ามติดตั้งเป็น Windows Service หรือ Scheduled Task จริงที่ทำงานค้างในระบบจนกว่าจะเข้าสู่ Phase ถัดไป
4. **Credential Security Policy:** ใน Phase 3.8.1 และ 3.8.2 ไม่มีการคัดลอกหรือฝัง Credential จริงในแพ็กเกจเด็ดขาด และรหัสผ่านที่ป้อนบนเครื่องปลายทางจะถูกเข้ารหัสด้วย AES-256-GCM AEAD ทันที
5. **ข้อจำกัดที่ยังไม่ได้ทดสอบกับระบบจริง (Real-World Limitations):**
   - การติดตั้งเป็น Native Windows Service (เช่น ผ่าน NSSM หรือ WinSW) ในระบบ Production
   - ความเข้ากันได้กับระบบปฏิบัติการ Windows เวอร์ชัน 32-bit (เนื่องจากทดสอบเฉพาะ x64)
   - การรันบน PHP เวอร์ชันต่ำกว่า 8.1 (ระบบบังคับตรวจขั้นต่ำ 8.1.0)
   - การ Reboot เครื่อง Windows ทางกายภาพจริง (ระบุเป็น Not Tested ชัดเจน)


