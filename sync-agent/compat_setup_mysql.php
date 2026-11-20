<?php
/**
 * RxCore Sync Agent — MySQL/MariaDB Test Environment Setup
 * Phase 3.2.1: Compatibility Test
 * 
 * หน้าที่:
 * 1. สร้างฐานข้อมูลทดสอบ rxcore_hosxp_test บน MySQL/MariaDB ท้องถิ่น
 * 2. สร้างตารางจำลองตามโครงสร้าง HOSxP: opitemrece, drugitems, vn_stat
 * 3. ใส่ข้อมูลสังเคราะห์ (Synthetic Test Data) ครอบคลุมทั้ง 10 กรณีทดสอบ (ไม่มีข้อมูลผู้ป่วยจริง 100%)
 * 4. สร้างบัญชี Read-only เฉพาะ rxcore_ro_test@localhost (สิทธิ์เฉพาะ SELECT)
 */

class MySqlTestEnvironment {

    const DB_NAME   = 'rxcore_hosxp_test';
    const TEST_USER = 'rxcore_ro_test';
    const TEST_PASS = 'RoTestPass_2026!';
    const HOST      = '127.0.0.1';
    const PORT      = 3306;

    /**
     * สร้างหรือรีเซ็ตฐานข้อมูลและตารางทดสอบบน MySQL
     * 
     * @param string $rootUser บัญชี Admin สำหรับ Setup (ค่าเริ่มต้น: root)
     * @param string $rootPass รหัสผ่าน Admin (ค่าเริ่มต้น: ว่างเปล่าสำหรับ XAMPP)
     * @return array ผลการติดตั้ง
     */
    public static function setup(string $rootUser = 'root', string $rootPass = ''): array {
        try {
            // 1. เชื่อมต่อ MySQL ด้วยบัญชี Administrative เพื่อสร้าง DB และ User
            $adminPdo = new PDO(
                "mysql:host=" . self::HOST . ";port=" . self::PORT . ";charset=utf8mb4",
                $rootUser,
                $rootPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            // 2. สร้างฐานข้อมูลทดสอบ
            $adminPdo->exec("CREATE DATABASE IF NOT EXISTS `" . self::DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $adminPdo->exec("USE `" . self::DB_NAME . "`");

            // 3. สร้างตาราง HOSxP จำลอง
            $adminPdo->exec("DROP TABLE IF EXISTS `opitemrece`");
            $adminPdo->exec("DROP TABLE IF EXISTS `vn_stat`");
            $adminPdo->exec("DROP TABLE IF EXISTS `drugitems`");
            $adminPdo->exec("DROP TABLE IF EXISTS `ovstdiag`");
            $adminPdo->exec("DROP TABLE IF EXISTS `patient`");
            $adminPdo->exec("DROP TABLE IF EXISTS `ovst`");

            // ตาราง drugitems
            $adminPdo->exec("
                CREATE TABLE `drugitems` (
                    `icode` VARCHAR(7) NOT NULL,
                    `name` VARCHAR(150) NOT NULL,
                    `tpu_code_list` VARCHAR(255) NULL,
                    PRIMARY KEY (`icode`),
                    INDEX `idx_tpu_code_list` (`tpu_code_list`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // ตาราง vn_stat
            $adminPdo->exec("
                CREATE TABLE `vn_stat` (
                    `vn` VARCHAR(13) NOT NULL,
                    `hn` VARCHAR(9) NULL,
                    `hcode` VARCHAR(5) NULL,
                    `vstdate` DATE NULL,
                    PRIMARY KEY (`vn`),
                    INDEX `idx_vstdate` (`vstdate`),
                    INDEX `idx_hcode` (`hcode`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // ตาราง opitemrece
            $adminPdo->exec("
                CREATE TABLE `opitemrece` (
                    `hos_guid` VARCHAR(38) NULL,
                    `vn` VARCHAR(13) NULL,
                    `icode` VARCHAR(7) NULL,
                    `vstdate` DATE NULL,
                    `qty` DOUBLE(10,2) NULL,
                    INDEX `idx_vstdate_vn` (`vstdate`, `vn`),
                    INDEX `idx_icode` (`icode`),
                    INDEX `idx_hos_guid` (`hos_guid`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // ตาราง ovstdiag
            $adminPdo->exec("
                CREATE TABLE `ovstdiag` (
                    `ovst_diag_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `vn` VARCHAR(13) NOT NULL,
                    `icd10` VARCHAR(10) NULL,
                    `vstdate` DATE NULL,
                    INDEX `idx_ovstdiag_vn` (`vn`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // ตาราง patient (โครงสร้างสังเคราะห์ ไม่มีข้อมูลส่วนบุคคลจริง)
            $adminPdo->exec("
                CREATE TABLE `patient` (
                    `hn` VARCHAR(9) NOT NULL PRIMARY KEY,
                    `birthday` DATE NULL,
                    `sex` VARCHAR(1) NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // ตาราง ovst
            $adminPdo->exec("
                CREATE TABLE `ovst` (
                    `vn` VARCHAR(13) NOT NULL PRIMARY KEY,
                    `hn` VARCHAR(9) NULL,
                    `vstdate` DATE NULL,
                    INDEX `idx_ovst_vstdate` (`vstdate`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // 4. บรรจุข้อมูลสังเคราะห์ (Synthetic Test Data) สำหรับ Phase 3.2
            require_once __DIR__ . '/mock_dataset.php';

            // drugitems
            $stmtDrug = $adminPdo->prepare("INSERT INTO `drugitems` (`icode`, `name`, `tpu_code_list`) VALUES (:icode, :name, :tpu_code_list)");
            foreach (MockDatasetPhase32::getDrugItemsRows() as $row) {
                $stmtDrug->execute($row);
            }

            // vn_stat
            $stmtVn = $adminPdo->prepare("INSERT INTO `vn_stat` (`vn`, `hn`, `hcode`, `vstdate`) VALUES (:vn, :hn, :hcode, :vstdate)");
            foreach (MockDatasetPhase32::getVnStatRows() as $row) {
                $stmtVn->execute($row);
            }

            // opitemrece
            $stmtOp = $adminPdo->prepare("INSERT INTO `opitemrece` (`hos_guid`, `vn`, `icode`, `vstdate`, `qty`) VALUES (:hos_guid, :vn, :icode, :vstdate, :qty)");
            foreach (MockDatasetPhase32::getOpitemreceRows() as $row) {
                $stmtOp->execute($row);
            }

            // 5. สร้างและจำกัดสิทธิ์ผู้ใช้สำหรับทดสอบสิทธิ์ (Testing Accounts)
            $db = self::DB_NAME;

            // 5.1 บัญชี Read-only แท้จริง (เฉพาะ SELECT)
            $roUser = self::TEST_USER;
            $roPass = self::TEST_PASS;
            $adminPdo->exec("CREATE USER IF NOT EXISTS '{$roUser}'@'localhost' IDENTIFIED BY '{$roPass}'");
            $adminPdo->exec("ALTER USER '{$roUser}'@'localhost' IDENTIFIED BY '{$roPass}'");
            $adminPdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{$roUser}'@'localhost'");
            $adminPdo->exec("GRANT SELECT ON `{$db}`.* TO '{$roUser}'@'localhost'");

            // 5.2 บัญชีที่มีสิทธิ์เขียน (Write User - SELECT, INSERT, UPDATE, DELETE) สำหรับทดสอบปฏิเสธสิทธิ์
            $writerUser = 'rxcore_writer_test';
            $writerPass = 'WriterPass_2026!';
            $adminPdo->exec("CREATE USER IF NOT EXISTS '{$writerUser}'@'localhost' IDENTIFIED BY '{$writerPass}'");
            $adminPdo->exec("ALTER USER '{$writerUser}'@'localhost' IDENTIFIED BY '{$writerPass}'");
            $adminPdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{$writerUser}'@'localhost'");
            $adminPdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$db}`.* TO '{$writerUser}'@'localhost'");

            // 5.3 บัญชีที่สืบทอดสิทธิ์ผ่าน Role (Role-Inherited Write User)
            try {
                $adminPdo->exec("CREATE ROLE IF NOT EXISTS 'rxcore_test_role'");
                $adminPdo->exec("GRANT SELECT, INSERT, UPDATE ON `{$db}`.* TO 'rxcore_test_role'");
                $adminPdo->exec("CREATE USER IF NOT EXISTS 'rxcore_role_user'@'localhost' IDENTIFIED BY 'RolePass_2026!'");
                $adminPdo->exec("ALTER USER 'rxcore_role_user'@'localhost' IDENTIFIED BY 'RolePass_2026!'");
                $adminPdo->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'rxcore_role_user'@'localhost'");
                $adminPdo->exec("GRANT 'rxcore_test_role' TO 'rxcore_role_user'@'localhost'");
                $adminPdo->exec("SET DEFAULT ROLE 'rxcore_test_role' FOR 'rxcore_role_user'@'localhost'");
            } catch (\Throwable $e) {
                // หากเซิร์ฟเวอร์รุ่นเก่าไม่รองรับ ROLE
            }

            $adminPdo->exec("FLUSH PRIVILEGES");

            return [
                'success'     => true,
                'message'     => 'สร้างฐานข้อมูลทดสอบและผู้ใช้ Read-only / Write / Role บน MySQL สำเร็จ',
                'database'    => self::DB_NAME,
                'user'        => self::TEST_USER,
                'password'    => self::TEST_PASS,
                'writer_user' => 'rxcore_writer_test',
                'role_user'   => 'rxcore_role_user',
                'tables'      => ['opitemrece', 'drugitems', 'vn_stat', 'ovstdiag', 'patient', 'ovst'],
                'drug_rows'   => count(MockDatasetPhase32::getDrugItemsRows()),
                'vn_rows'     => count(MockDatasetPhase32::getVnStatRows()),
                'op_rows'     => count(MockDatasetPhase32::getOpitemreceRows()),
            ];

        } catch (PDOException $e) {
            return [
                'success' => false,
                'error'   => 'MySQL Setup Failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * สร้าง PDO Connection โดยใช้บัญชี Read-only
     */
    public static function getReadOnlyPdo(): PDO {
        $dsn = "mysql:host=" . self::HOST . ";port=" . self::PORT . ";dbname=" . self::DB_NAME . ";charset=utf8mb4";
        return new PDO($dsn, self::TEST_USER, self::TEST_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
