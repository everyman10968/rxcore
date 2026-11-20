# RxCore — Phase 1: Project Setup & Web App Foundation

ระบบวิเคราะห์และติดตามข้อมูลการจ่ายยาจาก รพ.สต. 6 แห่ง (Hospital Pharmacy Monitoring System)

---

## 📌 สรุปภาพรวม Phase 1

ในระยะนี้เป็นการวางโครงสร้างโปรเจกต์และ Web Application พื้นฐาน (Foundation) ตามข้อกำหนด:
- พัฒนาด้วย **PHP 8.2+**, **HTML5**, **Vanilla CSS**, **JavaScript**, **Bootstrap 5.3**, และ **Chart.js**
- แยกการกำหนดค่า (Configuration) ออกจากซอร์สโค้ดผ่าน `.env` และ `.env.example`
- ป้องกันข้อมูลรับรองและคีย์ความลับ โดยไม่มีการเปิดเผย API Keys ในฝั่ง Frontend
- รองรับภาษาไทย UTF-8 และออกแบบธีม **Light Theme** สำหรับงานโรงพยาบาลและสาธารณสุข
- ใช้ข้อมูลตัวอย่างที่ระบุชัดเจนว่าเป็น **ข้อมูลจำลอง (Mock Data)** พร้อมแถบเตือนสีเหลืองทุกหน้า
- ไม่มีการเชื่อมต่อฐานข้อมูลจริง และไม่มีการพัฒนา Sync Agent ในระยะนี้

---

## 📂 โครงสร้างไฟล์ในโปรเจกต์

```text
rxcore/
├── .env                          # ค่าตัวแปรสภาพแวดล้อม (Local Development)
├── .env.example                  # Template ตัวอย่างสำหรับตั้งค่าระบบ
├── .gitignore                    # ป้องกันการ Commit ข้อมูลความลับ
├── index.php                     # จุดเริ่มต้นระบบ (Entry Router)
├── README.md                     # เอกสารแนะนำและคู่มือการใช้งาน
├── config/
│   ├── app.php                   # ระบบโหลด .env, Session, Timezone, UTF-8, Base URL
│   ├── database.php              # โครงสร้างคอนฟิก Supabase Cloud และ Masking
│   └── mock_data.php             # ข้อมูลจำลอง 6 รพ.สต., ยา, รายการจ่ายยา, ผู้ใช้
├── includes/
│   ├── auth.php                  # ระบบตรวจสอบสิทธิ์, สลับ Role (Admin/User)
│   ├── header.php                # ส่วนหัว HTML, CSS, Google Fonts
│   ├── navbar.php                # Top Navigation Bar, สัญญาณเครือข่าย, สลับสิทธิ์
│   ├── sidebar.php               # เมนูด้านซ้ายพร้อม Active State อัตโนมัติ
│   ├── mock_banner.php           # แถบแจ้งเตือนข้อมูลจำลองมาตรฐาน
│   └── footer.php                # ส่วนท้ายหน้าเว็บและ Script Bundle
├── pages/
│   ├── login.php                 # 1. หน้าจอ Login พร้อมปุ่มทดสอบสิทธิ์ด่วน
│   ├── logout.php                # สิ้นสุด Session และออกจากระบบ
│   ├── dashboard.php             # 2. หน้าจอ Dashboard พร้อม KPI, กราฟ, รายการล่าสุด
│   ├── drugs.php                 # 3. หน้าจอ ทะเบียนยา (ค้นหา, กรองหมวด, Modal Mock)
│   ├── reports.php               # 4. หน้าจอ รายงานการใช้ยา (กรองช่วงวันที่, รพ.สต.)
│   ├── health_centers.php        # 5. หน้าจอ ข้อมูล รพ.สต. ทั้ง 6 แห่ง และสถานะออนไลน์
│   ├── sync_status.php           # 6. หน้าจอ สถานะการ Sync ข้อมูล และ Log
│   ├── settings.php              # 7. หน้าจอ ตั้งค่าระบบและการเชื่อมต่อ Supabase
│   └── users.php                 # 8. หน้าจอ จัดการผู้ใช้และสิทธิ์ (RBAC Matrix)
├── api/
│   └── index.php                 # API Healthcheck Gateway สำหรับ Phase ถัดไป
├── database/
│   ├── schema.sql                # SQL DDL Schema สำหรับ Supabase Cloud (PostgreSQL)
│   └── README.md                 # คู่มือการเตรียมฐานข้อมูล
└── assets/
    ├── css/
    │   └── custom.css            # Hospital Light Theme และ Responsive Styles
    ├── js/
    │   └── main.js               # จัดการ Sidebar, Toast Notifications, Mock Actions
    └── images/                   # ไดเรกทอรีสำหรับรูปภาพและ Assets
```

---

## 🚀 วิธีติดตั้งและเปิดใช้งานผ่าน XAMPP

1. วางโฟลเดอร์โปรเจกต์ไว้ที่ `C:\xampp\htdocs\rxcore`
2. เปิด **XAMPP Control Panel** และตรวจสอบให้โมดูล **Apache** อยู่ในสถานะ `Running` (Port 80)
3. ตรวจสอบว่าไฟล์ `.env` มีอยู่จริง (หากยังไม่มี ให้คัดลอกมาจาก `.env.example`)
4. เปิดเว็บเบราว์เซอร์แล้วเข้าสู่ระบบที่:
   ```
   http://localhost/rxcore/
   ```
5. ระบบจะนำทางไปยังหน้า Login โดยอัตโนมัติ สามารถกดปุ่ม **"ทดสอบเข้าสู่ระบบแบบ Admin"** หรือ **"ทดสอบเข้าสู่ระบบแบบ User (รพ.สต.)"** เพื่อเข้าใช้งานและตรวจสอบสิทธิ์ได้ทันที
