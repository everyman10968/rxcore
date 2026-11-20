<?php
/**
 * RxCore Sync Agent — Atomic File Storage Engine
 * Phase 3.6.1: Reliability & Performance Hardening
 * 
 * หน้าที่และข้อกำหนด:
 * 1. เขียนไฟล์ชั่วคราวในไดเรกทอรีเดียวกับไฟล์เป้าหมาย (รับประกันอยู่ใน Filesystem เดียวกัน)
 * 2. ตรวจสอบความครบถ้วนของข้อมูลและทดสอบ json_decode กลับคืนก่อนการสลับไฟล์
 * 3. ใช้กลไก Atomic Replacement ที่ปลอดภัยสำหรับ Windows (รองรับ Lock และ Backup Rollback)
 * 4. ป้องกันการเขียนชนกันจากหลาย Process ด้วย flock(LOCK_EX)
 * 5. หากเกิดข้อผิดพลาดในการเขียน ไฟล์เดิมต้องคงสภาพเดิม 100% ไม่เสียหาย
 * 6. รองรับ Fault Injection เพื่อการทดสอบความคงทนในทุกสถานการณ์
 */

class AgentAtomicFile {

    public const LOCK_SUFFIX = '.lock';
    public const TMP_PREFIX  = '.tmp_';
    public const BAK_SUFFIX  = '.bak';

    /**
     * บันทึกข้อมูล Array ลงไฟล์ JSON แบบ Atomic พร้อมป้องกันความขัดแย้ง
     * 
     * @param string $filePath เส้นทางไฟล์เป้าหมาย
     * @param array $data ข้อมูลที่ต้องการบันทึก
     * @param callable|null $faultInjector ฟังก์ชันจำลองความผิดพลาดสำหรับทดสอบ: fn(string $stage, string $tempFile, string $targetFile)
     * @return bool สำเร็จหรือไม่
     * @throws RuntimeException เมื่อเกิดข้อผิดพลาดร้ายแรง
     */
    public static function writeJson(string $filePath, array $data, ?callable $faultInjector = null): bool {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException("ไม่สามารถสร้างไดเรกทอรีสำหรับ State File: {$dir}");
            }
        }

        // 1. จัดการ Process-level Exclusion Lock File
        $lockPath = $dir . DIRECTORY_SEPARATOR . '.' . basename($filePath) . self::LOCK_SUFFIX;
        $lockFp = @fopen($lockPath, 'c+');
        if (!$lockFp) {
            throw new RuntimeException("ไม่สามารถเปิด Lock File สำหรับ Atomic Write: {$lockPath}");
        }

        if (!flock($lockFp, LOCK_EX)) {
            fclose($lockFp);
            throw new RuntimeException("ไม่สามารถครอบครอง Lock File สำหรับ Atomic Write: {$lockPath}");
        }

        $tempFile = null;
        $backupFile = null;

        try {
            // 2. สร้าง Temp File ในไดเรกทอรีเดียวกัน
            $tempFile = $dir . DIRECTORY_SEPARATOR . self::TMP_PREFIX . basename($filePath) . '.' . getmypid() . '.' . bin2hex(random_bytes(6));
            
            // Fault Injection: ก่อนเริ่มเขียน
            if ($faultInjector !== null) {
                $faultInjector('before_write', $tempFile, $filePath);
            }

            // 3. แปลงข้อมูลเป็น JSON
            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new RuntimeException("JSON Encoding ล้มเหลว: " . json_last_error_msg());
            }

            // 4. เขียนลง Temp File พร้อมตรวจสอบ Bytes
            $fpTemp = @fopen($tempFile, 'wb');
            if (!$fpTemp) {
                throw new RuntimeException("ไม่สามารถสร้าง Temp File: {$tempFile}");
            }

            $bytesWritten = fwrite($fpTemp, $encoded);
            fflush($fpTemp);
            fclose($fpTemp);

            if ($bytesWritten !== strlen($encoded)) {
                throw new RuntimeException("เขียนข้อมูลลง Temp File ไม่ครบถ้วน (เขียนได้ {$bytesWritten} จาก " . strlen($encoded) . " ไบต์)");
            }

            // Fault Injection: หลังเขียนลง Temp File เสร็จ (เช่น จำลองไฟล์เสียหรือเขียนไม่ครบ)
            if ($faultInjector !== null) {
                $faultInjector('after_write', $tempFile, $filePath);
            }

            // 5. ตรวจสอบความถูกต้อง (Verification): อ่านกลับและทดสอบ Parse JSON
            $readBack = @file_get_contents($tempFile);
            if ($readBack === false) {
                throw new RuntimeException("ไม่สามารถอ่าน Temp File เพื่อตรวจสอบความถูกต้องได้");
            }

            $decoded = json_decode($readBack, true);
            if (!is_array($decoded)) {
                throw new RuntimeException("Temp File เสียหาย: ไม่สามารถแปลง JSON กลับเป็น Array ได้");
            }

            // Fault Injection: ก่อนกระบวนการ Swap
            if ($faultInjector !== null) {
                $faultInjector('before_swap', $tempFile, $filePath);
            }

            // 6. Windows-Safe Atomic Replacement
            if (file_exists($filePath)) {
                $backupFile = $filePath . '.' . bin2hex(random_bytes(4)) . self::BAK_SUFFIX;
                
                // ย้ายไฟล์เดิมไปเป็น Backup ชั่วคราว
                if (!@rename($filePath, $backupFile)) {
                    throw new RuntimeException("ไม่สามารถย้ายไฟล์เดิมไปยัง Backup ชั่วคราวได้");
                }

                // สลับ Temp File เข้าเป็นไฟล์เป้าหมาย
                if (!@rename($tempFile, $filePath)) {
                    // หากสลับล้มเหลว ให้กู้คืนไฟล์เดิมจาก Backup ทันที
                    @rename($backupFile, $filePath);
                    throw new RuntimeException("สลับ Temp File เป็นไฟล์เป้าหมายล้มเหลว (กู้คืนไฟล์เดิมสำเร็จ)");
                }

                // ลบ Backup เมื่อสลับสำเร็จเรียบร้อย
                @unlink($backupFile);
            } else {
                // ไฟล์เป้าหมายยังไม่มีอยู่ สามารถ rename ตรงๆ ได้ทันที
                if (!@rename($tempFile, $filePath)) {
                    throw new RuntimeException("ไม่สามารถย้าย Temp File เป็นไฟล์เป้าหมายใหม่ได้");
                }
            }

            return true;

        } catch (\Throwable $e) {
            // หากเกิดข้อผิดพลาด ให้ทำความสะอาด Temp File ทันที
            if ($tempFile !== null && file_exists($tempFile)) {
                @unlink($tempFile);
            }
            // หากมี Backup ค้างอยู่และเป้าหมายหายไป ให้กู้คืน
            if ($backupFile !== null && file_exists($backupFile) && !file_exists($filePath)) {
                @rename($backupFile, $filePath);
            }
            throw $e;
        } finally {
            // ปลด Lock และปิด Lock File เสมอ
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    /**
     * อ่านไฟล์ JSON แบบปลอดภัย พร้อมแชร์การล็อก (Shared Lock)
     * 
     * @param string $filePath
     * @param array $default ค่าเริ่มต้นหากไฟล์ไม่มีอยู่หรือเสียหาย
     * @return array
     */
    public static function readJson(string $filePath, array $default = []): array {
        if (!file_exists($filePath)) {
            return $default;
        }

        $dir = dirname($filePath);
        $lockPath = $dir . DIRECTORY_SEPARATOR . '.' . basename($filePath) . self::LOCK_SUFFIX;
        
        // หากมี Lock File ให้ลองอ่านภายใต้ Shared Lock
        $fp = @fopen($filePath, 'rb');
        if (!$fp) {
            return $default;
        }

        @flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        @flock($fp, LOCK_UN);
        fclose($fp);

        if ($content === false || trim($content) === '') {
            return $default;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return $default;
        }

        return $decoded;
    }
}
