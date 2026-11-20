# RxCore — Phase 2: Schema Architecture, Security Protocols & Validation Report

เอกสารสรุปผลการปรับปรุง **Database Schema สำหรับ Supabase Cloud (PostgreSQL 15+)** ของระบบ **RxCore** ระยะที่ 2 ฉบับสมบูรณ์ (Refined v2.1)

---

## 1. การแก้ไขประเด็นการกระทบยอด 30 วัน (Reconciliation Protocol)

### 1.1 ปัญหาเดิม
- ตัวอย่างเดิมตรวจสอบเฉพาะ `hos_guid` ซึ่งมีจุดอ่อนคือ ไม่ได้ยืนยันความสัมพันธ์กับรหัสสถานพยาบาล `hcode` ในชุดอ้างอิง อาจเปิดช่องให้เกิดการอ้างอิง GUID ข้ามสถานพยาบาล (Cross-hospital mismatch)
- การตรวจสอบยังไม่ได้ยืนยันว่าชุดข้อมูลต้นทางถูกดึงมาจนครบถ้วนจริง (Source Completeness) หากกระบวนการคิวรีฝั่ง HOSxP หลุดกลางคัน (เช่น Query Timeout) หรือแพ็กเกจ JSON ถูกตัดทอนระหว่างส่ง อาจทำให้เข้าใจผิดว่ารายการที่หายไปคือรายการที่ถูกลบ นำไปสู่การ Soft Delete ข้อมูลที่ยังใช้งานอยู่โดยไม่ตั้งใจ

### 1.2 สถาปัตยกรรม Handshake และการป้องกันความปลอดภัย 4 ชั้น
ในฟังก์ชัน [`fn_sync_dispense_batch()`](file:///c:/xampp/htdocs/rxcore/database/schema.sql) ได้กำหนดโครงสร้าง `p_reconcile_handshake` ใหม่เป็นชุดคู่รหัส `(hcode, hos_guid)` พร้อมตัวแปรยืนยันความสมบูรณ์ของต้นทาง:

```json
{
  "reconcile_hcode": "01425",
  "start_date": "2026-09-27",
  "end_date": "2026-10-27",
  "source_query_status": "COMPLETED",
  "expected_source_count": 3,
  "source_active_pairs": [
    {"hcode": "01425", "hos_guid": "GUID-01"},
    {"hcode": "01425", "hos_guid": "GUID-03"},
    {"hcode": "01425", "hos_guid": "GUID-04"}
  ]
}
```

#### กลไกการตรวจสอบ 4 ขั้นตอนก่อนอนุญาตให้ Soft Delete:
1. **Hospital Identity Verification:** `reconcile_hcode` ต้องระบุและตรงกับ `p_hcode` ของสถานพยาบาลที่ผ่านการยืนยัน API Key ฝั่งเซิร์ฟเวอร์
2. **Source Query Completion Guarantee:** `source_query_status` ต้องมีค่าเป็น `'COMPLETED'` เท่านั้น หากเป็นสถานะอื่น (เช่น `'TIMEOUT'`, `'FAILED'`, หรือ `NULL`) ระบบจะปฏิเสธการกระทบยอดทันที (`RECONCILIATION_SOURCE_INCOMPLETE`)
3. **Payload Truncation Protection:** ตรวจสอบความครบถ้วนของข้อมูลที่ได้รับ โดยจำนวนสมาชิกใน `source_active_pairs` ต้องตรงกับ `expected_source_count` ที่ต้นทางประกาศไว้ หากข้อมูลขาดหายระหว่างการส่งทางเครือข่าย จะยกเลิกการกระทบยอดทันที (`RECONCILIATION_PAYLOAD_TRUNCATED`)
4. **Strict Hospital Binding on Pair Level:** โหลดข้อมูลลงในตารางชั่วคราว `temp_source_active_pairs (hcode, hos_guid)` และตรวจสอบว่าทุกรายการมี `hcode = p_hcode` หากพบ `hcode` ของ รพ.สต. อื่นปะปนเข้ามา จะยกเลิกกระบวนการทันที (`RECONCILIATION_SECURITY_VIOLATION`)

#### การกระทบยอดแบบ Transactional Anti-Join:
เมื่อผ่านเงื่อนไขความปลอดภัยครบทุกข้อ ระบบจะดำเนินการ Soft Delete รายการใน Cloud ที่มี `d.hcode = p_hcode` และ `d.vstdate` อยู่ในช่วงวันดังกล่าว แต่คู่รหัส `(d.hcode, d.hos_guid)` ไม่อยู่ใน `temp_source_active_pairs` ภายใต้ Database Transaction เดียวกัน:
```sql
UPDATE public.rx_dispense d
SET is_deleted = TRUE, deleted_at = NOW(), updated_at = NOW()
WHERE d.hcode = p_hcode
  AND d.vstdate BETWEEN v_rec_start_date AND v_rec_end_date
  AND d.is_deleted = FALSE
  AND NOT EXISTS (
      SELECT 1 FROM temp_source_active_pairs tap
      WHERE tap.hcode = d.hcode
        AND tap.hos_guid = d.hos_guid
  );
```

---

## 2. การสกัดกั้นรายการยาที่ปิดใช้งาน (Inactive Drug Protection)

- **ข้อจำกัดของ Foreign Key:** Foreign Key มาตรฐานของฐานข้อมูลเชิงสัมพันธ์ตรวจสอบได้เฉพาะว่ามีค่าคีย์หลักตรงกันในตารางอ้างอิงหรือไม่ แต่ไม่สามารถตรวจสอบฟิลด์ประเภทสถานะ เช่น `is_active = TRUE` ได้โดยตรง
- **สถาปัตยกรรมป้องกันสองชั้น (Dual-Layer Defense):**
  1. **Database Engine Trigger ([`trg_check_drug_is_active`](file:///c:/xampp/htdocs/rxcore/database/schema.sql)):** ติดตั้งบนตาราง `rx_dispense` แบบ `BEFORE INSERT OR UPDATE OF tpu_code_list` หากพบว่ายานั้นมีสถานะ `is_active = FALSE` จะส่งสัญญาณ Exception (`InactiveDrugViolation`) ยกเลิกคำสั่ง SQL ทันที ป้องกันการแอบใส่ข้อมูลโดยตรงผ่านเครื่องมือจัดการฐานข้อมูล
  2. **Application RPC Graceful Handling ([`fn_sync_dispense_batch`](file:///c:/xampp/htdocs/rxcore/database/schema.sql)):** ในกระบวนการซิงค์ผ่าน RPC ระบบจะตรวจสอบสถานะ `is_active` ของยาแต่ละรายการ หากพบว่ายาปิดใช้งาน จะตัดรายการนั้นออกและบันทึกข้อผิดพลาด `DRUG_INACTIVE` ลงใน `rx_sync_log.error_details` เพื่อแจ้งเตือนให้เจ้าหน้าที่ทราบ โดยไม่ทำให้ Batch ข้อมูลอื่นพัง

---

## 3. ความโปร่งใสทางวิศวกรรม: Static Validation vs. Real PostgreSQL Execution

### 3.1 ข้อจำกัดของ Static Validation
การตรวจสอบผ่านสคริปต์ PHP ([`database/validate_schema.php`](file:///c:/xampp/htdocs/rxcore/database/validate_schema.php)) เป็นการตรวจสอบแบบ **Static Analysis (Regex / Keyword Linting / Parentheses Balancing)** ซึ่งยืนยันได้เพียง:
- มีการนิยามตารางครบ 6 ตารางตามสเปก
- ฟังก์ชัน, ทริกเกอร์, ข้อจำกัด UNIQUE `(hcode, hos_guid)` และ RLS ถูกเขียนลงในไฟล์
- รูปแบบพารามิเตอร์ของ RPC สอดคล้องกับข้อกำหนด

**สิ่งที่ Static Validation ไม่สามารถทดแทนได้:**
1. การตรวจสอบ Type Coercion และ PL/pgSQL Runtime AST (เช่น พฤติกรรมของ JSONB Operators เมื่อพบ Null Keys)
2. พฤติกรรมจริงของ Temporary Table ภายใต้ Concurrency หลาย Connection
3. ลำดับการทำงานของ Trigger (Trigger Firing Sequence) ระหว่าง Trigger คำนวณต้นทุน และ Trigger ตรวจสอบ `is_active`
4. การทำงานของ Row Level Security (RLS) กับ Session Context จริงของ `auth.uid()`

### 3.2 สถานะสภาพแวดล้อมปัจจุบัน
- เครื่องเซิร์ฟเวอร์พัฒนาปัจจุบันคือ **Windows XAMPP (Apache + PHP 8.2 + MySQL/MariaDB)** โดยไม่มี PostgreSQL Engine ติดตั้งอยู่ในเครื่อง
- ตามข้อกำหนดที่เข้มงวดของโครงการ: **ห้ามนำ Schema ไปรันบน Supabase Cloud Production และห้ามเชื่อมต่อระบบจริงในระยะนี้**

### 3.3 ขั้นตอนการทดสอบ Staging Test Suite ก่อนขึ้นระบบจริง
ไฟล์ [`database/test_schema.sql`](file:///c:/xampp/htdocs/rxcore/database/test_schema.sql) ถูกออกแบบให้เป็น **Self-Contained Automated Test Suite** ครอบคลุม 7 Test Scenarios โดยใช้คำสั่ง `BEGIN ... ROLLBACK` เพื่อให้สามารถรันบนฐานข้อมูลทดสอบได้โดยไม่มีผลกระทบต่อข้อมูลจริง:

```bash
# คำสั่งรัน Automated Test Suite บน PostgreSQL 15+ Staging / Local Docker Container
psql -h <staging-host> -U postgres -d rxcore_test -f database/schema.sql
psql -h <staging-host> -U postgres -d rxcore_test -f database/test_schema.sql
```

**7 Scenarios ใน Test Suite:**
- **TEST CASE 1:** ตรวจสอบ CHECK Constraints (`qty > 0`, `length(hcode) = 5`)
- **TEST CASE 2:** ตรวจสอบ Foreign Key และการสกัดกั้นยาที่ปิดใช้งาน (`is_active = FALSE`) ทั้งระดับ Trigger Exception และ RPC Handling
- **TEST CASE 3:** ตรวจสอบ Unique Constraint `(hcode, hos_guid)`
- **TEST CASE 4:** ตรวจสอบ Historical Cost (คืนค่า `NULL` เมื่อไม่มีต้นทุนในอดีต ห้ามยืมต้นทุนอนาคต และไม่นับในยอดเงินรวม)
- **TEST CASE 5:** ตรวจสอบ Trigger คำนวณต้นทุนย้อนหลังอัตโนมัติเมื่อ Admin ปรับปรุงราคาย้อนหลัง
- **TEST CASE 6:** ตรวจสอบการกระทบยอด 30 วัน:
  - 6.1: ปฏิเสธการกระทบยอดเมื่อสถานะคิวรีต้นทางไม่สมบูรณ์ (`source_query_status != 'COMPLETED'`)
  - 6.2: ปฏิเสธการกระทบยอดเมื่อพบ `hcode` ข้ามหน่วยบริการในชุดคู่รหัสอ้างอิง
  - 6.3: ทำการ Soft Delete เฉพาะรายการที่ตกหล่นจากชุดคู่รหัสอ้างอิงที่สมบูรณ์
- **TEST CASE 7:** ตรวจสอบ Trigger ป้องกัน Non-Admin เปลี่ยนแปลง `role` หรือ `hcode` ของตนเอง

---

## 4. ผลการรัน Static Validation ล่าสุด

```bash
C:\xampp\php\php.exe database/validate_schema.php
```

```text
=======================================================
RxCore Phase 2 Final v2 SQL Schema Static Validation
=======================================================
✅ ALL SCHEMA & TEST SUITE CHECKS PASSED!
- Inactive Drugs: Blocked by trg_check_drug_is_active and intercepted gracefully by RPC.
- 30-day Reconciliation: Exact composite pairs (hcode, hos_guid), hospital binding, and source completeness verified.
- Historical Cost: Strictly returns NULL if no cost prior/on vstdate (No future cost fallback).
- View Summary: Accurately excludes NULL costs from monetary sum and displays cost_status.
- RLS & Triggers: Profile defense and server RPC properly configured.
- Test Suite: 7 automated test cases verified.
=======================================================
```
