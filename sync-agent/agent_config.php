<?php
/**
 * RxCore Sync Agent — Configuration Manager
 *
 * จัดการการโหลด/บันทึก/เข้ารหัส Config สำหรับการเชื่อมต่อ HOSxP MySQL และ Supabase Cloud
 * ข้อมูลลับทั้งหมด (hosxp_password, sync_api_key, supabase_anon_key) ถูกเข้ารหัสด้วย AES-256-GCM (AEAD)
 */

class AgentConfig {
    
    private string $configDir;
    private string $configFile;
    private string $encryptionKey;
    private string $cipher = 'aes-256-gcm';
    private string $legacyCipher = 'aes-256-cbc';
    
    /**
     * ค่าเริ่มต้นของ Config (คง agent_mode = 'mock' เมื่อยังไม่มีไฟล์ เพื่อรองรับ Packaging Test Suite)
     */
    private array $defaults = [
        'hosxp_host'          => '127.0.0.1',
        'hosxp_port'          => 3306,
        'hosxp_database'      => 'hosxp_pcu',
        'hosxp_username'      => 'rxcore_ro',
        'hosxp_password'      => '',
        'hcode'               => '',
        'facility_name'       => '',
        'agent_mode'          => 'mock',
        'supabase_url'        => '',
        'supabase_anon_key'   => '',
        'sync_api_key'        => '',
        'central_web_url'     => '',
        'auto_sync_enabled'      => true,
        'sync_slots'             => ['09:00', '14:00', '17:00'],
        'moph_notify_enabled'    => false,
        'moph_notify_on_manual'  => false,
        'moph_client_key'        => '',
        'moph_secret_key'        => '',
        'created_at'             => '',
        'updated_at'          => '',
    ];
    
    public function __construct(?string $configDir = null) {
        $this->configDir  = $configDir ?? (__DIR__ . '/config');
        $this->configFile = $this->configDir . '/agent_settings.enc.json';
        
        $machineId = php_uname('n') . '|' . ($configDir ?? __DIR__);
        $this->encryptionKey = hash('sha256', $machineId, true);
        
        $this->ensureConfigDir();
    }
    
    private function ensureConfigDir(): void {
        if (!is_dir($this->configDir)) {
            mkdir($this->configDir, 0700, true);
        }
        
        $htaccessFile = $this->configDir . '/.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, "Deny from all\n");
        }
    }
    
    /**
     * เข้ารหัส string ด้วย AES-256-GCM พร้อม Authentication Tag (AEAD)
     */
    private function encrypt(string $plaintext): string {
        $ivLength = openssl_cipher_iv_length($this->cipher); // 12 bytes
        $iv = openssl_random_pseudo_bytes($ivLength);
        $tag = '';
        $rawEncrypted = openssl_encrypt($plaintext, $this->cipher, $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        return base64_encode($iv) . '::' . base64_encode($tag) . '::' . base64_encode($rawEncrypted);
    }
    
    /**
     * ถอดรหัส string ด้วย AES-256-GCM พร้อมรองรับ backward compatibility (AES-256-CBC)
     */
    private function decrypt(string $ciphertext): string|false {
        $parts = explode('::', $ciphertext);

        if (count($parts) === 3) {
            $iv = base64_decode($parts[0], true);
            $tag = base64_decode($parts[1], true);
            $encrypted = base64_decode($parts[2], true);
            if ($iv !== false && $tag !== false && $encrypted !== false) {
                $decrypted = openssl_decrypt($encrypted, $this->cipher, $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
                if ($decrypted !== false) {
                    return $decrypted;
                }
            }
            return false;
        }

        if (count($parts) === 2) {
            $iv = base64_decode($parts[0], true);
            $encrypted = base64_decode($parts[1], true);
            if ($iv !== false && $encrypted !== false) {
                $decrypted = openssl_decrypt($encrypted, $this->legacyCipher, $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
                if ($decrypted !== false) {
                    return $decrypted;
                }
            }
        }

        $data = base64_decode($ciphertext, true);
        if ($data !== false && strpos($data, '::') !== false) {
            $legacyParts = explode('::', $data, 2);
            if (count($legacyParts) === 2) {
                [$legacyIv, $legacyEnc] = $legacyParts;
                return openssl_decrypt(base64_encode($legacyEnc), $this->legacyCipher, $this->encryptionKey, 0, $legacyIv);
            }
        }

        return false;
    }
    
    /**
     * โหลด Config จากไฟล์ (ถอดรหัส Secret Fields ทั้งหมด)
     */
    public function load(): array {
        if (!file_exists($this->configFile)) {
            return $this->defaults;
        }
        
        $json = file_get_contents($this->configFile);
        $config = json_decode($json, true);
        
        if (!is_array($config)) {
            return $this->defaults;
        }
        
        foreach (['hosxp_password', 'sync_api_key', 'supabase_anon_key', 'moph_client_key', 'moph_secret_key'] as $secretField) {
            $encField = $secretField . '_encrypted';
            if (!empty($config[$encField])) {
                $decrypted = $this->decrypt((string)$config[$encField]);
                $config[$secretField] = ($decrypted !== false) ? $decrypted : '';
                unset($config[$encField]);
            }
        }
        
        return array_merge($this->defaults, $config);
    }
    
    /**
     * บันทึก Config ลงไฟล์ (เข้ารหัส Secret Fields ทั้งหมด ห้ามเก็บ Plaintext)
     */
    public function save(array $config): bool {
        $existing = $this->hasConfig() ? $this->load() : $this->defaults;
        $toSave = array_merge($this->defaults, $existing, $config);
        
        foreach (['hosxp_password', 'sync_api_key', 'supabase_anon_key', 'moph_client_key', 'moph_secret_key'] as $secretField) {
            $plainVal = (string)($toSave[$secretField] ?? '');
            if ($plainVal !== '') {
                $toSave[$secretField . '_encrypted'] = $this->encrypt($plainVal);
            } else {
                $toSave[$secretField . '_encrypted'] = '';
            }
            unset($toSave[$secretField]);
        }
        
        $toSave['updated_at'] = date('Y-m-d H:i:s');
        if (empty($toSave['created_at'])) {
            $toSave['created_at'] = $toSave['updated_at'];
        }
        
        $json = json_encode($toSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return file_put_contents($this->configFile, $json) !== false;
    }
    
    /**
     * คืนค่า Config ที่ปลอดภัย (ไม่มีรหัสผ่านหรือคีย์ลับแบบเต็ม) สำหรับแสดงผล UI
     */
    public function getSafeConfig(): array {
        $config = $this->load();
        $hasSavedPassword       = !empty($config['hosxp_password']);
        $hasSavedSyncKey        = !empty($config['sync_api_key']);
        $hasSavedAnonKey        = !empty($config['supabase_anon_key']);
        $hasSavedMophClientKey  = !empty($config['moph_client_key']);
        $hasSavedMophSecretKey  = !empty($config['moph_secret_key']);

        $maskedSyncKey = '';
        if ($hasSavedSyncKey) {
            $k = (string)$config['sync_api_key'];
            $maskedSyncKey = strlen($k) > 12 ? (substr($k, 0, 8) . '••••' . substr($k, -4)) : '••••••••';
        }

        $maskSimple = static function (string $val): string {
            if ($val === '') return '';
            return strlen($val) > 8 ? (substr($val, 0, 4) . '••••' . substr($val, -4)) : '••••••••';
        };

        $rawMophClient          = (string)($config['moph_client_key'] ?? '');
        $rawMophSecret          = (string)($config['moph_secret_key'] ?? '');

        $config['hosxp_password']          = '';
        $config['sync_api_key']            = '';
        $config['supabase_anon_key']       = '';
        $config['moph_client_key']         = '';
        $config['moph_secret_key']         = '';
        $config['has_saved_password']      = $hasSavedPassword;
        $config['has_saved_sync_key']      = $hasSavedSyncKey;
        $config['has_saved_anon_key']      = $hasSavedAnonKey;
        $config['has_saved_moph_client']   = $hasSavedMophClientKey;
        $config['has_saved_moph_secret']   = $hasSavedMophSecretKey;
        $config['sync_api_key_masked']     = $maskedSyncKey;
        $config['moph_client_key_masked']  = $maskSimple($rawMophClient);
        $config['moph_secret_key_masked']  = $maskSimple($rawMophSecret);
        return $config;
    }
    
    public function hasConfig(): bool {
        return file_exists($this->configFile);
    }
    
    public function clear(): bool {
        if (file_exists($this->configFile)) {
            return unlink($this->configFile);
        }
        return true;
    }
}
