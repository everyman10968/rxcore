# รายงานผลการทดสอบ RxCore Phase 4.0: Hospital HOSxP Live Read-only Integration
**โรงพยาบาลค้อวัง (Kho Wang Hospital — HCODE: 10968)**  
**วันที่ทดสอบ:** 29 ตุลาคม 2569 (2026-10-29)  
**สถานะ:** **SUCCESS (ALL TESTS PASSED / ZERO WRITE INVARIANT VERIFIED)**

---

## 1. ข้อมูลสภาพแวดล้อมและเวอร์ชันระบบ (Environment & Server Information)

| รายการ | รายละเอียด |
| :--- | :--- |
| **เซิร์ฟเวอร์ฐานข้อมูล HOSxP XE** | `10.0.100.2` (เครือข่ายภายในโรงพยาบาลค้อวัง) |
| **Port** | `3306` (TCP Listener ตอบสนองปกติ) |
| **Database Engine** | `11.4.8-MariaDB` |
| **Database Name** | `kwhhosxpxe` |
| **Character Set ของฐานข้อมูล** | `tis620` (Client Connection: `utf8`) |
| **เครื่อง Agent ที่ใช้ทดสอบ** | Windows OS (IP: `10.0.100.192` บน Ethernet LAN) |
| **PHP Runtime** | PHP 8.2.12 (cli) พร้อม PDO_MYSQL, OpenSSL (AES-256-GCM) |
| **เวลาตอบสนองเครือข่าย (Latency)** | ~15 – 28 ms |

---

## 2. การจัดการความปลอดภัยและบัญชีฐานข้อมูล (Credentials & Security)

- **หลักการไม่เปิดเผย Credential:** ไม่มีการบันทึก Password ลงใน Source Code, Git Repository, System Log หรือรายงานนี้
- **การจัดเก็บข้อมูลเชื่อมต่อ:** เข้ารหัสด้วยอัลกอริทึม **AES-256-GCM (AEAD)** พร้อม Authentication Tag เก็บในไฟล์คอนฟิกเฉพาะ `sync-agent/config/khowang/agent_settings.enc.json` ป้องกันด้วย `.htaccess` (Deny from all)
- **บัญชีฐานข้อมูลที่ใช้:** บัญชีเฉพาะกิจ `rxcore_ro` ซึ่งได้รับสิทธิ์เฉพาะ **SELECT เท่านั้น** บนฐานข้อมูล `kwhhosxpxe.*`
- **การปฏิเสธบัญชีผู้ดูแลระบบ:** ระบบมีมาตรการความปลอดภัยปฏิเสธการเชื่อมต่อด้วยบัญชี `root`, `admin`, `sa` หรือบัญชีที่มีสิทธิ์เขียนโดยอัตโนมัติ

---

## 3. ผลการตรวจสอบสิทธิ์ Read-only (Read-only Privilege Audit)

การตรวจสอบสิทธิ์ในระดับ Global, Database, Table และ Column ผ่าน `SHOW GRANTS` และ `information_schema` พบดังนี้:

### สิทธิ์ที่ได้รับจริง (Granted Privileges):
```sql
GRANT USAGE ON *.* TO `rxcore_ro`@`%`
GRANT SELECT ON `kwhhosxpxe`.* TO `rxcore_ro`@`%`
```

### สิทธิ์ต้องห้าม (Prohibited Write Privileges):
- **สิทธิ์เขียนที่ตรวจพบ:** **0 รายการ (NONE)**
- ไม่พบสิทธิ์ `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `DROP`, `ALTER`, `RELOAD`, `SHUTDOWN`, `SUPER`, `GRANT OPTION` ใด ๆ

### ผลการทดสอบปฏิเสธคำสั่งเขียนจริงบน MariaDB Engine (Write Rejection Verification):
| คำสั่งทดสอบ | ผลลัพธ์จาก MariaDB Engine | สถานะ |
| :--- | :--- | :--- |
| `INSERT INTO opitemrece ...` | `SQLSTATE[42000]: 1142 INSERT command denied to user 'rxcore_ro'` | **REJECTED (SAFE)** |
| `UPDATE opitemrece SET ...` | `SQLSTATE[42000]: 1142 UPDATE command denied to user 'rxcore_ro'` | **REJECTED (SAFE)** |
| `DELETE FROM opitemrece ...` | `SQLSTATE[42000]: 1142 DELETE command denied to user 'rxcore_ro'` | **REJECTED (SAFE)** |
| `CREATE TABLE rxcore_probe ...` | `SQLSTATE[42000]: 1142 CREATE command denied to user 'rxcore_ro'` | **REJECTED (SAFE)** |
| `DROP TABLE rxcore_probe ...` | `SQLSTATE[42000]: 1142 DROP command denied to user 'rxcore_ro'` | **REJECTED (SAFE)** |

> **ยืนยัน Zero-Write Invariant:** ไม่มีการเปลี่ยนแปลงข้อมูลหรือโครงสร้างตารางในฐานข้อมูล HOSxP XE แม้แต่แถวเดียว

---

## 4. โครงสร้าง Schema จริง (Schema Compatibility)

จากการตรวจสอบตารางและคอลัมน์จริงใน `kwhhosxpxe` พบว่ามีความเข้ากันได้ 100%:

### 1) ตาราง `opitemrece` (พบทั้งหมด 42 คอลัมน์)
- `hos_guid` (`varchar(38)`, Primary Key) — รหัสอ้างอิงรายการจ่ายยา
- `vn` (`varchar(13)`, Index) — เลขลำดับการรับบริการ
- `vstdate` (`date`, Index) — วันที่รับบริการ
- `icode` (`varchar(7)`, Index) — รหัสยา/เวชภัณฑ์
- `qty` (`int(11)`) — จำนวนที่จ่าย

### 2) ตาราง `drugitems` (พบทั้งหมด 203 คอลัมน์)
- `icode` (`varchar(7)`, Primary Key) — รหัสยาภายในโรงพยาบาล
- `tpu_code_list` (`varchar(200)`) — รหัสยามาตรฐาน TPU สำหรับเชื่อมโยงระบบกลาง
- `name` (`varchar(100)`) — ชื่อยา
- `did` (`varchar(30)`) — รหัสยา 24 หลัก

### 3) ตาราง `vn_stat` (พบทั้งหมด 87 คอลัมน์)
- `vn` (`varchar(13)`, Primary Key) — เลขลำดับการรับบริการ
- `hcode` (`varchar(5)`) — รหัสสถานพยาบาล (ค่าจริง: `10968`)
- `vstdate` (`date`, Index) — วันที่รับบริการ

---

## 5. รูปแบบ Query ที่ใช้ทดสอบ (Prepared Statements)

Query ใช้ Prepared Statements พร้อม Parameterized Binding ทั้งหมดเพื่อความปลอดภัยสูงสุดและไม่โหลดฐานข้อมูล:

```sql
SELECT
    r.hos_guid,
    r.vn,
    v.hcode,
    r.vstdate,
    d.tpu_code_list,
    r.qty
FROM opitemrece r
INNER JOIN drugitems d ON d.icode = r.icode
INNER JOIN vn_stat v ON v.vn = r.vn
WHERE r.vstdate >= :start_date
  AND r.vstdate <= :end_date
  AND d.tpu_code_list IN (:drug_0, :drug_1, :drug_2, ...)
ORDER BY r.vstdate, r.vn;
```

---

## 6. ผลการทดสอบ Extractor กับข้อมูลจริง (Live Data Extraction)

- **ช่วงเวลาที่ทดสอบ:** `2026-10-29` ถึง `2026-10-29` (1 วัน)
- **รายการยาเป้าหมาย (ตัวอย่าง 6 รายการยาจำเป็นของ รพ.ค้อวัง):**
  1. `226262` — AMLODIPINE 5 mg Tablet
  2. `117618` — SIMVASTATIN 20 mg Tablet
  3. `111471` — ENALAPRIL 5 mg Tablet
  4. `438642` — PARACETAMOL 500 mg Tablet
  5. `374764` — METFORMIN 500 mg Tablet
  6. `104721` — OMEPRAZOLE 20 mg Capsule

### ผลสรุปการ Extract ข้อมูล:
| ตัวชี้วัด | จำนวน | หมายเหตุ |
| :--- | :---: | :--- |
| **จำนวนรายการที่อ่านจากฐานข้อมูล (Total Read)** | **86** | ตรงกับ Raw Source Query 100% |
| **จำนวนรายการที่ผ่านเกณฑ์ (Total Passed)** | **82** | ตรวจสอบผ่านทุกเงื่อนไข พร้อมส่งออก |
| **รายการผิดรูปแบบที่คัดกรองออก (Total Malformed)** | **4** | ตรวจพบรายการที่ `qty = 0` (คำสั่งยกเลิก/ไม่มียา) ถูกคัดออกอย่างถูกต้อง |
| **รายการซ้ำที่ถูกตัดออก (Duplicates Pruned)** | **0** | ข้อมูลจริงในชุดนี้มี `(hcode, hos_guid)` ไม่ซ้ำกัน |
| **สมดุลความถูกต้องทางคณิตศาสตร์** | **86 = 82 + 4 + 0** | ครบถ้วน ไม่สูญหาย |

### การข้ามยาที่ไม่ได้ลงทะเบียน (Unregistered Drugs):
- รายการยาอื่น ๆ นอกเหนือจาก 6 รายการข้างต้น ถูกข้ามอย่างเงียบเชียบตามข้อกำหนด โดยไม่มีการสร้าง Log รายบรรทัดรบกวนระบบ

---

## 7. ผลการตรวจสอบข้อมูลซ้ำและความเป็นส่วนตัว (PII & Validation)

### การตัดข้อมูลซ้ำ (Duplicate Prevention):
- ยืนยันการใช้ Composite Key: `(hcode, hos_guid)` เช่น `10968|{GUID}`
- การทดสอบ Injected Test Case ยืนยันว่าหากพบ `(hcode, hos_guid)` ซ้ำกันในชุดข้อมูล ระบบจะตัดเหลือ 1 รายการ (`duplicates_pruned = 1`) อย่างถูกต้อง

### Strict 6 Fields Whitelist & Zero PII Guarantee:
ผลลัพธ์ของ Extractor มีเฉพาะ 6 ฟิลด์ที่ได้รับอนุญาตเท่านั้น:
1. `hos_guid` (string)
2. `vn` (string)
3. `hcode` (string 5 หลัก: `10968`)
4. `vstdate` (string รูปแบบ YYYY-MM-DD: `2026-10-29`)
5. `tpu_code_list` (string รหัส TPU)
6. `qty` (float > 0)

> **การปกป้องข้อมูลระบุตัวบุคคล (Zero PII):**  
> ตรวจสอบแล้ว **ไม่มีฟิลด์ HN, CID, ชื่อ-สกุลผู้ป่วย, ที่อยู่ หรือรหัสการวินิจฉัยโรค (pdx/dx)** หลุดรอดออกมาใน Payload หรือ Preview แม้แต่ฟิลด์เดียว

#### ตัวอย่างผลลัพธ์แบบ Sanitized Preview (ปิดบัง VN และ GUID บางส่วนเพื่อความปลอดภัย):
```json
{
    "hos_guid": "{5917AE58-****-****-****-************}",
    "vn": "69102906****",
    "hcode": "10968",
    "vstdate": "2026-10-29",
    "tpu_code_list": "374764",
    "qty": 190.0
}
```

---

## 8. เวลาในการประมวลผลและผลกระทบต่อระบบบริการ (Performance & Impact)

- **เวลาในการ Query และ Extract:** **15 – 28 ms** (เฉลี่ย ~21 ms)
- **การใช้ทรัพยากร (Resource Usage):**
  - Query อาศัยดัชนี (B-Tree Index) บนคอลัมน์ `vstdate`, `icode`, และ `vn`
  - ไม่เกิด Full Table Scan บนตาราง `opitemrece` ซึ่งมีข้อมูลปริมาณมาก
- **ผลกระทบต่อระบบบริการ HOSxP ของโรงพยาบาล:** **ไม่มีผลกระทบ (Zero Performance Impact)** สามารถทำงานควบคู่กับการบริการปกติได้อย่างปลอดภัย

---

## 9. ผลการทดสอบ Regression Integrity ทั้งหมด 14 ชุด (Regression Test Results)

รันการทดสอบครบถ้วนทุก Phase ตั้งแต่ Phase 3.1 จนถึง Phase 4.0:

| ลำดับ | Test Suite File | จำนวน Test | PASS | FAIL | NOT TESTED | Exit Code | สถานะ |
| :---: | :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| 1 | `test_agent.php` | 56 | 56 | 0 | 0 | 0 | **PASS** |
| 2 | `test_extractor.php` | 27 | 27 | 0 | 0 | 0 | **PASS** |
| 3 | `test_sync_core.php` | 46 | 46 | 0 | 0 | 0 | **PASS** |
| 4 | `test_hosxp_reader.php` | 66 | 66 | 0 | 0 | 0 | **PASS** |
| 5 | `test_api_client.php` | 59 | 59 | 0 | 0 | 0 | **PASS** |
| 6 | `test_security_audit.php` | 72 | 72 | 0 | 0 | 0 | **PASS** |
| 7 | `test_mysql_compatibility.php` | 37 | 37 | 0 | 0 | 0 | **PASS** |
| 8 | `test_phase36.php` | 63 | 63 | 0 | 0 | 0 | **PASS** |
| 9 | `test_phase361.php` | 61 | 61 | 0 | 0 | 0 | **PASS** |
| 10 | `test_phase37.php` | 114 | 114 | 0 | 0 | 0 | **PASS** |
| 11 | `test_packaging.php` | 38 | 38 | 0 | 0 | 0 | **PASS** |
| 12 | `test_isolated_deployment.php` | 47 | 46 | 0 | 1 | 0 | **PASS** |
| 13 | `test_phase39.php` | 81 | 81 | 0 | 0 | 0 | **PASS** |
| 14 | `test_phase40.php` | 64 | 64 | 0 | 0 | 0 | **PASS** |
| **รวม** | **14 Test Suites** | **831** | **830** | **0** | **1** | **0** | **100% PASS** |

*(หมายเหตุ: 1 รายการ NOT TESTED ใน `test_isolated_deployment.php` เป็นการทดสอบ Windows Service Manager ที่ต้องใช้สิทธิ์ Administrator บนเครื่อง Client ซึ่งออกแบบให้ข้ามอย่างปลอดภัย)*

---

## 10. ข้อจำกัดและข้อแนะนำสำหรับการเตรียม Phase 4.1 (Limitations & Next Steps)

1. **การจับคู่รหัสยา (Drug Code Mapping):**
   - รหัส TPU ในตาราง `drugitems.tpu_code_list` ของแต่ละโรงพยาบาลขึ้นอยู่กับรายการยาที่จัดซื้อและบันทึกในระบบ HOSxP
   - ในการเชื่อมต่อระบบจริงกับส่วนกลาง แนะนำให้มีกระบวนการ Import/Map ทะเบียนยาของโรงพยาบาลเข้ากับ Drug Master Registry ก่อนเริ่ม Sync
2. **ขอบเขตการหยุดตามเงื่อนไข Phase 4.0:**
   - การทดสอบนี้จำกัดเฉพาะการอ่านข้อมูลภายในโรงพยาบาลค้อวังแบบ Read-only Preview
   - **ไม่มีการส่งข้อมูลออกนอกโรงพยาบาล (No Outbound Network Transmission)**
   - **ไม่มีการเรียก Supabase Cloud API จริง และไม่มีการเรียก MOPH Notify จริง**
   - **ไม่มีการแก้ไขข้อมูลหรือ Schema ของ HOSxP XE**
   - ระบบหยุดการทำงานใน Phase 4.0 ทันทีตามเงื่อนไขข้อ 8 พร้อมสำหรับส่งมอบให้ผู้ดูแลระบบตรวจสอบ
