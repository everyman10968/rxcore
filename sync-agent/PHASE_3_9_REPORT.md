# RxCore Phase 3.9: Read-only Database Connection & Pre-production Validation Report

**วันที่ดำเนินการ:** 29 ตุลาคม 2026  
**สภาพแวดล้อม:** Windows x64 | PHP 8.2.12 (cli) | MariaDB 10.4.32 (Local Test DB: `rxcore_hosxp_test`)  
**โหมดการทำงาน:** Dedicated Test Database & Strict Mock Outbound Protection (ห้ามเชื่อมต่อ HOSxP Production, Supabase Cloud หรือ MOPH Notify API จริง)  
**ผลการทดสอบภาพรวม:** ผ่านการตรวจสอบ 100% (766 PASS, 0 FAIL, 1 NOT TESTED)

---

## 1. วัตถุประสงค์และผลการพิสูจน์ (Phase 3.9 Achievements)

Phase 3.9 ได้รับการพัฒนาต่อจากแพ็กเกจ Phase 3.8.3 เพื่อทดสอบและพิสูจน์การเชื่อมต่อฐานข้อมูล MySQL/MariaDB จริงแบบ Read-only โดยใช้ฐานข้อมูลทดสอบสังเคราะห์ `rxcore_hosxp_test` โดยสามารถพิสูจน์ขีดความสามารถของ RxCore Sync Agent ได้ครบถ้วนตามเป้าหมาย:

1. **เชื่อมต่อฐานข้อมูล MySQL/MariaDB จริงได้สำเร็จ:** สามารถเชื่อมต่อผ่าน PHP PDO ด้วย Credential จากการตั้งค่าของ Agent โดยไม่มีการรั่วไหลของรหัสผ่านใน Log หรือ Diagnostics
2. **ตรวจสอบ Schema และสิทธิ์บัญชีฐานข้อมูล:** ตรวจสอบตารางและคอลัมน์ที่จำเป็น พร้อมทั้งประเมินสิทธิ์จริงจาก MySQL Data Dictionary ครอบคลุม Global, Schema, Table, Column, และ Role
3. **อ่านข้อมูลจากตารางที่กำหนดได้ถูกต้อง:** สามารถอ่านข้อมูลจาก `opitemrece`, `drugitems`, และ `vn_stat` ได้อย่างสมบูรณ์
4. **ดึงข้อมูลผ่าน Extractor ตามเงื่อนไขเดิม:** กรองเฉพาะยาในทะเบียน (TPU), ตัดข้อมูลซ้ำด้วย Composite Key `(hcode, hos_guid)`, ตรวจจับข้อมูลผิดรูปแบบ และจำกัด Payload เฉพาะ 6 ฟิลด์ที่ปลอดภัย (ไม่มี HN, CID หรือ PII)
5. **ป้องกันการเขียน แก้ไข หรือลบข้อมูลต้นทาง:** ยืนยันว่า MySQL Engine ปฏิเสธคำสั่ง INSERT, UPDATE, DELETE, DDL CREATE, DDL DROP และยืนยันจำนวนแถวของตารางต้นทางคงเดิม 100% (Zero Write Guarantee)
6. **ทำงานร่วมกับ Mock Mode เดิมได้อย่างราบรื่น:** ไม่กระทบต่อการทำงานของ Scheduler, Local Mock Transport และ Regression Tests เดิมทั้ง 12 ชุด

---

## 2. รายงาน Connection Diagnostics (ปราศจาก Credentials)

ผลการทดสอบการเชื่อมต่อและข้อมูล Diagnostic ผ่าน `AgentHosxpReader::getConnectionDiagnostics()`:

```json
{
    "host": "127.0.0.1",
    "port": 3306,
    "database": "rxcore_hosxp_test",
    "username": "rxcore_ro_test",
    "password_status": "[PROTECTED - AES-256-GCM ENCRYPTED]",
    "connected": true,
    "server_version": "10.4.32-MariaDB",
    "charset": "utf8mb4",
    "connection_time_ms": 1.48,
    "schema_valid": true,
    "tables_status": {
        "opitemrece": {
            "exists": true,
            "description": "ตารางบันทึกรายการจ่ายยาและเวชภัณฑ์",
            "missing_columns": [],
            "found_columns": ["hos_guid", "vn", "icode", "vstdate", "qty"]
        },
        "drugitems": {
            "exists": true,
            "description": "ตารางทะเบียนยา HOSxP (icode เชื่อม TPU)",
            "missing_columns": [],
            "found_columns": ["icode", "name", "tpu_code_list"]
        },
        "vn_stat": {
            "exists": true,
            "description": "ตารางการรับบริการผู้ป่วยนอก (ระบุสถานพยาบาล hcode)",
            "missing_columns": [],
            "found_columns": ["vn", "hn", "hcode", "vstdate"]
        }
    },
    "is_readonly": true,
    "prohibited_privs": [],
    "diagnostic_time": "2026-10-29 09:44:00"
}
```

### การทดสอบกรณีผิดปกติ (Connection Error Handling)
* **รหัสผ่านไม่ถูกต้อง (Wrong Password):** ปฏิเสธการเชื่อมต่อด้วย Error ภาษาไทยที่เป็นมิตร และปิดบังรหัสผ่านในข้อความ Error
* **Host / Port ติดต่อไม่ได้ (Unreachable Network):** ตัดการเชื่อมต่ออย่างนุ่มนวล (Timeout 3–5 วินาที) โดยไม่มี Unhandled Exception Crash และไม่แสดงรหัสผ่าน
* **บัญชีผู้ดูแลระบบ (Administrative Accounts):** บัญชี `root`, `admin`, `administrator`, `sa` ถูกปฏิเสธทันทีก่อนเปิด Connection เพื่อความปลอดภัย

---

## 3. รายงาน Database Privilege Audit

การตรวจสอบสิทธิ์แบบเชิงลึกจาก Information Schema Data Dictionary:

| ระดับการตรวจสอบ | แหล่งข้อมูล Dictionary / Command | บัญชี Read-only (`rxcore_ro_test`) | บัญชี Writer (`rxcore_writer_test`) | บัญชี Role (`rxcore_role_user`) |
|:---|:---|:---:|:---:|:---:|
| **Direct Grants** | `SHOW GRANTS FOR CURRENT_USER()` | `GRANT SELECT ON rxcore_hosxp_test.*` | `GRANT SELECT, INSERT, UPDATE, DELETE` | สืบทอดผ่าน Role |
| **Global Level** | `information_schema.USER_PRIVILEGES` | `USAGE` เท่านั้น | `USAGE` เท่านั้น | `USAGE` เท่านั้น |
| **Schema Level** | `information_schema.SCHEMA_PRIVILEGES` | `SELECT` เท่านั้น | `SELECT`, `INSERT`, `UPDATE`, `DELETE` | `SELECT`, `INSERT`, `UPDATE` |
| **Table Level** | `information_schema.TABLE_PRIVILEGES` | ไม่มีสิทธิ์เขียน | มีสิทธิ์เขียน | มีสิทธิ์เขียน |
| **Column Level** | `information_schema.COLUMN_PRIVILEGES` | ไม่มีสิทธิ์เขียน | ไม่มีสิทธิ์เขียน | ไม่มีสิทธิ์เขียน |
| **Role Level** | `information_schema.APPLICABLE_ROLES` | ไม่มี Role | ไม่มี Role | `rxcore_test_role` (เขียนได้) |
| **ผลการประเมิน** | **Agent Privilege Engine** | **PASS (`is_readonly: true`)** | **REJECTED (มีสิทธิ์เขียน)** | **REJECTED (มีสิทธิ์เขียน)** |

### การทดสอบการปฏิเสธคำสั่งเขียนจริงบน MySQL Engine (Write Operations Rejection Probe)
ทดสอบรันคำสั่งแก้ไขข้อมูลจริงบน MySQL ด้วยสิทธิ์ `rxcore_ro_test`:
* `INSERT INTO opitemrece ...` $\rightarrow$ **ถูกปฏิเสธ (SQLSTATE[42000] Access denied for user)**
* `UPDATE opitemrece SET qty = 99 ...` $\rightarrow$ **ถูกปฏิเสธ (SQLSTATE[42000] Access denied for user)**
* `DELETE FROM opitemrece WHERE ...` $\rightarrow$ **ถูกปฏิเสธ (SQLSTATE[42000] Access denied for user)**
* `CREATE TABLE rxcore_probe_tbl ...` $\rightarrow$ **ถูกปฏิเสธ (SQLSTATE[42000] Access denied for user)**
* `DROP TABLE rxcore_probe_tbl ...` $\rightarrow$ **ถูกปฏิเสธ (SQLSTATE[42000] Access denied for user)**
* **Safety Guard Invariant:** หากชื่อฐานข้อมูลไม่ใช่ Test Database (เช่น ระบุเป็น `hos`, `hospital`, `production`) ระบบจะปฏิเสธการรันคำสั่ง Probe ทันทีเพื่อป้องกันผลกระทบต่อระบบงานจริง

---

## 4. ผลการทดสอบ Schema และ Extractor

ทดสอบการดึงข้อมูลจริงบน MariaDB 10.4.32 ผ่าน `AgentDataExtractor`:

```
SQL Query Template:
SELECT r.hos_guid, r.vn, v.hcode, r.vstdate, d.tpu_code_list, r.qty
FROM opitemrece r
INNER JOIN drugitems d ON d.icode = r.icode
INNER JOIN vn_stat v ON v.vn = r.vn
WHERE r.vstdate >= :start_date AND r.vstdate <= :end_date
  AND d.tpu_code_list IN (:drug_0, :drug_1, ...)
ORDER BY r.vstdate, r.vn
```

### ผลการประมวลผลข้อมูลสังเคราะห์ (Synthetic Dataset Verification)
1. **การอ่านข้อมูลแบบ 1 วัน (2026-10-25):**
   * ข้อมูลที่อ่านได้: 2 รายการ
   * ข้อมูลที่ผ่านเกณฑ์: 2 รายการ (Paracetamol 500mg, Amoxicillin 500mg)
   * เปรียบเทียบกับผลนับอ้างอิง HOSxP (Test E): **MATCHED (2 = 2, ผลต่าง = 0)**
2. **การอ่านข้อมูลทั้งช่วง (2026-10-01 ถึง 2026-10-31):**
   * รายการซ้ำที่ถูกตัดด้วย Composite Key `(hcode, hos_guid)`: **1 รายการ** (`{DUP-HCODE-01425}`)
   * รายการที่ใช้ `hos_guid` เดียวกันแต่ต่าง `hcode` (`{SHARED-GUID-DIFF-HCODE}`): **ไม่ถูกตัด (คงอยู่ทั้ง 2 สถานพยาบาล 01425 และ 01426)**
   * รายการยาที่ไม่ได้ลงทะเบียนใน TPU: **ถูกกรองออกทั้งหมด (0 รายการหลุดเข้า Payload)**
   * รายการที่ผิดรูปแบบ (Malformed): **ตรวจจับได้ครบ 7 รายการ**
     * `missing_hos_guid`: 2 รายการ (ค่าว่าง และช่องว่าง)
     * `invalid_qty`: 2 รายการ (qty = 0 และ qty ติดลบ)
     * `missing_vn`: 1 รายการ
     * `missing_or_invalid_hcode`: 2 รายการ (hcode ว่าง และไม่ครบ 5 หลัก)
3. **การคัดกรองฟิลด์ความเป็นส่วนตัว (Zero PII Guarantee):**
   * Payload ข้อมูลที่ดึงได้ประกอบด้วย 6 ฟิลด์เท่านั้น: `hos_guid`, `vn`, `hcode`, `vstdate`, `tpu_code_list`, `qty`
   * ไม่มีฟิลด์ `hn`, `cid`, ชื่อ, นามสกุล หรือข้อมูลส่วนบุคคลของผู้ป่วยหลุดออกมาแม้แต่ฟิลด์เดียว
4. **การรับประกันการไม่แก้ไขข้อมูลต้นทาง (Zero Write Guarantee):**
   * ตาราง `opitemrece`: 19 แถว (ก่อน = 19, หลัง = 19)
   * ตาราง `drugitems`: 11 แถว (ก่อน = 11, หลัง = 11)
   * ตาราง `vn_stat`: 12 แถว (ก่อน = 12, หลัง = 12)

---

## 5. Synthetic Test Data และวิธีสร้างฐานข้อมูลทดสอบ

### 5.1 โครงสร้างฐานข้อมูลทดสอบ
ฐานข้อมูล: `rxcore_hosxp_test` (Collation: `utf8mb4_unicode_ci`) ประกอบด้วย 6 ตารางจำลอง:
1. `opitemrece` (hos_guid, vn, icode, vstdate, qty)
2. `drugitems` (icode, name, tpu_code_list)
3. `vn_stat` (vn, hn, hcode, vstdate)
4. `ovstdiag` (ovst_diag_id, vn, icd10, vstdate)
5. `patient` (hn, birthday, sex) — โครงสร้างจำลอง ไม่มีข้อมูลส่วนบุคคลจริง
6. `ovst` (vn, hn, vstdate)

### 5.2 ขั้นตอนการสร้างฐานข้อมูลทดสอบ
สั่งรันสคริปต์อัตโนมัติผ่าน PHP CLI:
```bash
php -r "require 'sync-agent/compat_setup_mysql.php'; print_r(MySqlTestEnvironment::setup());"
```

### 5.3 ผู้ใช้สำหรับทดสอบ (Test Users)
* `rxcore_ro_test@localhost`: มีเฉพาะสิทธิ์ `SELECT` บน `rxcore_hosxp_test.*`
* `rxcore_writer_test@localhost`: มีสิทธิ์ `SELECT, INSERT, UPDATE, DELETE` เพื่อทดสอบการปฏิเสธบัญชีที่มีสิทธิ์เขียน
* `rxcore_role_user@localhost`: สืบทอดสิทธิ์เขียนผ่าน Role `rxcore_test_role` เพื่อทดสอบการตรวจจับสิทธิ์จาก Role

---

## 6. ผลการทดสอบ Regression Test ครบทั้ง 13 Suites

รัน Regression Tests ทั้งหมดหลังการรวมโค้ด Phase 3.9:

| ลำดับ | Test Suite File | Phase | PASS | FAIL | NOT TESTED | Total | Exit Code | สถานะ |
|:---:|:---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| 1 | `test_agent.php` | 3.1 | 56 | 0 | 0 | 56 | 0 | PASS |
| 2 | `test_extractor.php` | 3.2 | 27 | 0 | 0 | 27 | 0 | PASS |
| 3 | `test_sync_core.php` | 3.3 | 46 | 0 | 0 | 46 | 0 | PASS |
| 4 | `test_hosxp_reader.php` | 3.4 | 66 | 0 | 0 | 66 | 0 | PASS |
| 5 | `test_api_client.php` | 3.5 | 59 | 0 | 0 | 59 | 0 | PASS |
| 6 | `test_security_audit.php` | 3.5.1 | 72 | 0 | 0 | 72 | 0 | PASS |
| 7 | `test_mysql_compatibility.php` | 3.2.1 | 37 | 0 | 0 | 37 | 0 | PASS |
| 8 | `test_phase36.php` | 3.6 | 63 | 0 | 0 | 63 | 0 | PASS |
| 9 | `test_phase361.php` | 3.6.1 | 61 | 0 | 0 | 61 | 0 | PASS |
| 10 | `test_phase37.php` | 3.7 | 114 | 0 | 0 | 114 | 0 | PASS |
| 11 | `test_packaging.php` | 3.8.1 | 38 | 0 | 0 | 38 | 0 | PASS |
| 12 | `test_isolated_deployment.php` | 3.8.2 | 46 | 0 | 1 | 47 | 0 | PASS |
| 13 | `test_phase39.php` | 3.9 | 81 | 0 | 0 | 81 | 0 | PASS |
| **รวม** | **13 Test Suites** | **3.1–3.9** | **766** | **0** | **1** | **767** | **0** | **100% SOUND** |

### สูตรการตรวจสอบความถูกต้องทางคณิตศาสตร์
$$\text{Total Test Cases (767)} = \text{PASS (766)} + \text{FAIL (0)} + \text{NOT TESTED (1)}$$

---

## 7. รายการไฟล์ที่มีการเปลี่ยนแปลงใน Phase 3.9

| ไฟล์ | ประเภท | รายละเอียดการเปลี่ยนแปลง |
|:---|:---:|:---|
| `sync-agent/test_phase39.php` | สร้างใหม่ | ชุดทดสอบการเชื่อมต่อ MySQL/MariaDB จริงแบบ Read-only และ Pre-production Validation (81 ข้อ) |
| `sync-agent/agent_hosxp_reader.php` | ปรับปรุง | เพิ่ม `isSafeTestDatabase()` ป้องกันการรันบน Production และเพิ่ม `getConnectionDiagnostics()` สำหรับรายงานสถานะโดยไม่มี Credential พร้อมรักษา Zero Write Statements Invariant |
| `sync-agent/audit_test_integrity.php` | ปรับปรุง | ขยายการตรวจนับและรัน Regression Test ครอบคลุมเป็น 13 Suites |
| `dist/rxcore-sync-agent/*` | Rebuild | ปรับปรุงแพ็กเกจด้วย Source Code และสร้าง Manifest SHA-256 ใหม่ (37 ไฟล์, 0 Secrets) |
| `sync-agent/PHASE_3_9_REPORT.md` | สร้างใหม่ | รายงานสรุปผลการดำเนินงานและผลการทดสอบ Phase 3.9 ฉบับสมบูรณ์ |

---

## 8. สรุปสิ่งที่ทดสอบจริง สิ่งที่จำลอง และสิ่งที่ยังไม่ได้ทดสอบ

### 8.1 สิ่งที่ทดสอบจริง (Tested Against Real Systems)
1. การเชื่อมต่อ PHP PDO กับฐานข้อมูล MySQL/MariaDB daemon จริง (127.0.0.1:3306)
2. การวิเคราะห์ Information Schema Data Dictionary และคำสั่ง `SHOW GRANTS` บนเซิร์ฟเวอร์ฐานข้อมูลจริง
3. การบังคับใช้นโยบาย Read-only และการปฏิเสธคำสั่ง INSERT, UPDATE, DELETE, DDL บน MySQL Engine จริง
4. การรัน SQL Multi-table JOIN (`opitemrece`, `drugitems`, `vn_stat`) และประมวลผลข้อมูลผ่าน AgentDataExtractor บน MySQL จริง
5. การเข้ารหัสและถอดรหัสรหัสผ่านด้วย AES-256-GCM AEAD จริง
6. การรัน Launcher Scripts (`.bat`) และการจัดการ Single Instance Mutex Lock บน Windows OS จริง

### 8.2 สิ่งที่จำลอง (Simulated / Mocked)
1. **ข้อมูลในฐานข้อมูล:** ใช้ข้อมูลสังเคราะห์ (Synthetic Test Data) 100% โดยไม่มีข้อมูลผู้ป่วยจริง
2. **Mock API Transport:** ข้อมูลการส่ง Sync ไปยัง API Server จำลองผ่าน In-memory Mock Transport โดยไม่มีการส่ง Network Socket ออกภายนอก
3. **MOPH Notify Adapter:** การแจ้งเตือนจำลองการทำงานภายในเครื่องโดยไม่เรียก MOPH Notify API ภายนอก

### 8.3 สิ่งที่ยังไม่ได้ทดสอบ (Not Tested)
1. **TC R.12 (Windows Machine Reboot):** ไม่สามารถสั่งรีบูตเครื่องคอมพิวเตอร์ Host จริงได้ในระหว่างเซสชัน จึงระบุเป็น `NOT TESTED`
2. **การเชื่อมต่อเครือข่ายข้ามเครื่อง (Cross-machine Network Latency):** การทดสอบทำบน Loopback Interface (127.0.0.1) ยังไม่ได้ทดสอบบน LAN ระหว่างเครื่อง Agent และเซิร์ฟเวอร์ HOSxP ของ รพ.สต.

---

## 9. ข้อจำกัดและประเด็นที่ต้องตรวจสอบก่อนเชื่อมต่อ Production (Pre-production Checklist)

ก่อนการนำ RxCore Sync Agent ไปเชื่อมต่อกับฐานข้อมูล HOSxP จริงในระดับ Production ต้องผ่านการตรวจสอบตาม Checklist ดังนี้:

- [ ] **การสร้างบัญชี Read-only บน HOSxP Production:**
  - ต้องสร้างบัญชีที่มีเฉพาะสิทธิ์ `SELECT` บนฐานข้อมูล HOSxP เท่านั้น (เช่น `GRANT SELECT ON hos.* TO 'rxcore_ro'@'192.168.x.x'`)
  - ห้ามให้สิทธิ์ `INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`, `GRANT OPTION` หรือสิทธิ์ระดับ Global เป็นอันขาด
  - ตรวจสอบว่าบัญชีไม่ได้สืบทอดสิทธิ์เขียนผ่าน Role ใด ๆ
- [ ] **การเปิดสิทธิ์การเข้าถึงจากเครื่อง Agent (IP Whitelisting):**
  - อนุญาตให้เชื่อมต่อ MySQL พอร์ต 3306 เฉพาะจาก IP ของเครื่อง Sync Agent ภายใน LAN ของ รพ.สต. เท่านั้น
  - ห้ามเปิดพอร์ต 3306 สู่ Internet ภายนอกโดยเด็ดขาด
- [ ] **การตรวจสอบ Schema และโครงสร้างตาราง HOSxP:**
  - รันการตรวจสอบผ่าน `AgentHosxpReader::validateSchema()` เพื่อยืนยันว่าตาราง `opitemrece`, `drugitems`, `vn_stat` และคอลัมน์ที่จำเป็นมีอยู่จริงบน HOSxP รุ่นที่ใช้งาน
- [ ] **การจับคู่รหัสยา (Drug Mapping Verification):**
  - ตรวจสอบว่าคอลัมน์ `drugitems.tpu_code_list` ได้รับการบันทึกรหัส TPU 6 หลักที่ตรงกับทะเบียนยาที่อนุญาต
- [ ] **การทดสอบอ่านข้อมูลแบบจำกัด (Trial Query):**
  - ทดสอบดึงข้อมูลย้อนหลัง 1 วัน โดยกดยืนยันการอ่าน และนำผลจำนวนที่ได้ไปเปรียบเทียบกับผลนับจาก SQL บน HOSxP เพื่อยืนยันความถูกต้อง 100%
- [ ] **มาตรการด้านความเป็นส่วนตัว:**
  - ยืนยันว่า Payload ที่เตรียมส่งมีเฉพาะ 6 ฟิลด์และไม่มี HN, CID หรือข้อมูลระบุตัวตนผู้ป่วย

---

## 10. เงื่อนไขการหยุดงาน (Stop Condition)

Phase 3.9 ได้เสร็จสิ้นลงอย่างสมบูรณ์ตามข้อกำหนดทุกประการ:
* **ไม่มีการเชื่อมต่อ HOSxP Production**
* **ไม่มีการส่งข้อมูลขึ้น Supabase Cloud**
* **ไม่มีการเรียก MOPH Notify API จริง**
* **ไม่มีการเปิดสิทธิ์เขียนให้กับบัญชีฐานข้อมูล**
* **หยุดการทำงานทันที ไม่เริ่ม Phase 4.0 หรือขยายขอบเขตงานเอง**
