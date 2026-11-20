<?php
/**
 * RxCore Sync Agent — MySQL Connection Tester
 * Phase 3.1: Foundation
 * 
 * ทดสอบการเชื่อมต่อ HOSxP MySQL พร้อมตรวจสอบสิทธิ์ Read-only
 * รองรับ Mock Mode สำหรับพัฒนาและทดสอบโดยไม่ต้องมี MySQL จริง
 */

class AgentConnection {
    
    /**
     * ผลลัพธ์การทดสอบมาตรฐาน
     */
    private array $result = [
        'success'        => false,
        'mode'           => 'unknown',   // 'mock' | 'real'
        'server_version' => null,
        'database'       => null,
        'is_readonly'    => null,
        'privileges'     => [],
        'prohibited'     => [],
        'diagnostics'    => null,
        'tables_found'   => 0,
        'hosxp_tables'   => [],          // ตารางสำคัญที่ตรวจพบ
        'error'          => null,
        'warnings'       => [],
        'duration_ms'    => 0,
        'tested_at'      => '',
    ];
    
    /**
     * ตารางสำคัญของ HOSxP ที่ Sync Agent ต้องอ่าน
     */
    private array $requiredHosxpTables = [
        'ovstdiag',      // วินิจฉัยผู้ป่วยนอก (ICD10)
        'opitemrece',    // รายการจ่ายยาผู้ป่วยนอก
        'drugitems',     // ทะเบียนยา
        'patient',       // ข้อมูลผู้ป่วย (สำหรับอายุ)
        'ovst',          // Visit ผู้ป่วยนอก (VN, vstdate)
    ];
    
    /**
     * ทดสอบการเชื่อมต่อ — เลือก Mock หรือ Real ตาม mode
     */
    public function testConnection(array $config): array {
        $startTime = microtime(true);
        $this->result['tested_at'] = date('Y-m-d H:i:s');
        
        if (($config['agent_mode'] ?? 'mock') === 'mock') {
            $this->result = array_merge($this->result, $this->mockConnection($config));
        } else {
            $this->result = array_merge($this->result, $this->realConnection($config));
        }
        
        $this->result['duration_ms'] = round((microtime(true) - $startTime) * 1000, 2);
        return $this->result;
    }
    
    /**
     * จำลองการเชื่อมต่อ (Mock) — ใช้เพื่อทดสอบ UI และ Flow โดยไม่ต้องมี MySQL จริง
     * ประเมินสิทธิ์ผ่าน Privilege Engine เดียวกันกับ Real mode โดยไม่ใช้ชื่อ Username ตัดสิน
     */
    private function mockConnection(array $config): array {
        // จำลอง Delay เล็กน้อยเพื่อให้ UI สมจริง
        usleep(300000); // 300ms
        
        // Validate ข้อมูลเบื้องต้น
        $validation = $this->validateConfig($config);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'mode'    => 'mock',
                'error'   => $validation['error'],
            ];
        }

        $database = $config['hosxp_database'] ?? 'hos';

        // ชุดจำลอง Grants และ Information Schema Catalog
        // รองรับการส่ง simulated_grants / simulated_catalog เข้ามาทดสอบได้ทุกกรณี
        $simulatedGrants = $config['simulated_grants'] ?? $config['mock_grants'] ?? [
            "GRANT USAGE ON *.* TO CURRENT_USER",
            "GRANT SELECT ON `{$database}`.* TO CURRENT_USER"
        ];

        $simulatedCatalog = $config['simulated_catalog'] ?? $config['mock_catalog'] ?? [
            'global'             => ['USAGE'],
            'schema'             => ['SELECT'],
            'table'              => [],
            'column'             => [],
            'roles'              => [],
            'role_table_grants'  => [],
            'role_column_grants' => [],
        ];

        $queriesExecuted = [
            "SHOW GRANTS FOR CURRENT_USER()",
            "SELECT CURRENT_ROLE()",
            "SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES",
            "SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE TABLE_SCHEMA = '{$database}'",
            "SELECT TABLE_NAME, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES WHERE TABLE_SCHEMA = '{$database}'",
            "SELECT TABLE_NAME, COLUMN_NAME, PRIVILEGE_TYPE FROM information_schema.COLUMN_PRIVILEGES WHERE TABLE_SCHEMA = '{$database}'",
            "SELECT ROLE_NAME, TABLE_NAME, PRIVILEGE_TYPE FROM information_schema.ROLE_TABLE_GRANTS WHERE TABLE_SCHEMA = '{$database}'",
            "SELECT ROLE_NAME, TABLE_NAME, COLUMN_NAME, PRIVILEGE_TYPE FROM information_schema.ROLE_COLUMN_GRANTS WHERE TABLE_SCHEMA = '{$database}'",
        ];

        // ประเมินสิทธิ์ครอบคลุมทุกระดับ (Global, Schema, Table, Column, Role)
        $privCheck = $this->evaluateAllPrivilegeLevels($simulatedGrants, $simulatedCatalog, $database);

        if (!$privCheck['has_select']) {
            return [
                'success'        => false,
                'mode'           => 'mock',
                'server_version' => 'MariaDB 10.4.32 (Mock)',
                'database'       => $database,
                'is_readonly'    => false,
                'privileges'     => $privCheck['effective_privileges'],
                'prohibited'     => $privCheck['prohibited_found'],
                'error'          => "ความปลอดภัยล้มเหลว: ไม่พบสิทธิ์ SELECT บนฐานข้อมูล '{$database}' จึงไม่สามารถอ่านข้อมูล HOSxP ได้",
                'diagnostics'    => [
                    'queries_executed' => $queriesExecuted,
                    'raw_grants'       => $simulatedGrants,
                    'inspected_levels' => ['Global', 'Schema', 'Table', 'Column', 'Role'],
                ],
            ];
        }

        if (!$privCheck['is_readonly']) {
            $prohibitedList = implode(', ', $privCheck['prohibited_found']);
            return [
                'success'        => false,
                'mode'           => 'mock',
                'server_version' => 'MariaDB 10.4.32 (Mock)',
                'database'       => $database,
                'is_readonly'    => false,
                'privileges'     => $privCheck['effective_privileges'],
                'prohibited'     => $privCheck['prohibited_found'],
                'error'          => "ความปลอดภัยล้มเหลว (Security Violation): ตรวจพบสิทธิ์เขียนข้อมูล ({$prohibitedList}) บนฐานข้อมูล '{$database}' — ระบบ RxCore บังคับใช้เฉพาะบัญชี Read-only (SELECT เท่านั้น) เพื่อความปลอดภัยสูงสุด",
                'diagnostics'    => [
                    'queries_executed' => $queriesExecuted,
                    'raw_grants'       => $simulatedGrants,
                    'inspected_levels' => ['Global', 'Schema', 'Table', 'Column', 'Role'],
                ],
            ];
        }
        
        // จำลองการเชื่อมต่อสำเร็จ (บัญชี Read-only แท้จริง)
        return [
            'success'        => true,
            'mode'           => 'mock',
            'server_version' => 'MariaDB 10.4.32 (Mock)',
            'database'       => $database,
            'is_readonly'    => true,
            'privileges'     => $privCheck['effective_privileges'],
            'prohibited'     => [],
            'tables_found'   => 287,
            'hosxp_tables'   => $this->requiredHosxpTables,
            'error'          => null,
            'warnings'       => [
                'โหมดจำลอง (Mock): ผลลัพธ์นี้ผ่านการวิเคราะห์ครอบคลุมระดับ Global, Schema, Table, Column และ Role',
                'กรุณาเปลี่ยนเป็นโหมดจริง (Real) เมื่อต้องการทดสอบกับ MySQL Server จริง'
            ],
            'diagnostics'    => [
                'queries_executed' => $queriesExecuted,
                'raw_grants'       => $simulatedGrants,
                'inspected_levels' => ['Global', 'Schema', 'Table', 'Column', 'Role'],
            ],
        ];
    }
    
    /**
     * ทดสอบการเชื่อมต่อจริงกับ MySQL Server
     * ตรวจสอบ:
     * 1. เชื่อมต่อได้หรือไม่
     * 2. เลือกฐานข้อมูลได้หรือไม่
     * 3. สิทธิ์เป็น Read-only จริงจาก MySQL ครอบคลุม Global, Schema, Table, Column, Role หรือไม่
     * 4. พบตารางสำคัญของ HOSxP หรือไม่
     */
    private function realConnection(array $config): array {
        // Validate ข้อมูลเบื้องต้น
        $validation = $this->validateConfig($config);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'mode'    => 'real',
                'error'   => $validation['error'],
            ];
        }
        
        $host     = $config['hosxp_host'];
        $port     = (int)($config['hosxp_port'] ?? 3306);
        $database = $config['hosxp_database'];
        $username = $config['hosxp_username'];
        $password = $config['hosxp_password'] ?? '';
        
        $warnings = [];
        
        // 1. ทดสอบการเชื่อมต่อ
        $mysqli = mysqli_init();
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);

        try {
            $connected = @$mysqli->real_connect($host, $username, $password, '', $port);
            if (!$connected || $mysqli->connect_errno) {
                $errorCode = $mysqli->connect_errno;
                $errorMsg  = $mysqli->connect_error;
                $friendlyError = $this->translateMysqlError($errorCode, $errorMsg, $host, $port);
                return [
                    'success' => false,
                    'mode'    => 'real',
                    'error'   => $friendlyError,
                ];
            }
        } catch (\mysqli_sql_exception $e) {
            $errorCode = $e->getCode();
            $errorMsg  = $e->getMessage();
            $friendlyError = $this->translateMysqlError($errorCode, $errorMsg, $host, $port);
            return [
                'success' => false,
                'mode'    => 'real',
                'error'   => $friendlyError,
            ];
        }
        
        // ดึงเวอร์ชัน Server
        $serverVersion = $mysqli->server_info;
        
        // 2. เลือกฐานข้อมูล
        try {
            if (!$mysqli->select_db($database)) {
                $error = "ไม่พบฐานข้อมูล '{$database}' บนเซิร์ฟเวอร์ หรือไม่มีสิทธิ์เข้าถึง (Error: {$mysqli->error})";
                $mysqli->close();
                return [
                    'success'        => false,
                    'mode'           => 'real',
                    'server_version' => $serverVersion,
                    'error'          => $error,
                ];
            }
        } catch (\mysqli_sql_exception $e) {
            $mysqli->close();
            return [
                'success'        => false,
                'mode'           => 'real',
                'server_version' => $serverVersion,
                'error'          => $this->translateMysqlError($e->getCode(), $e->getMessage(), $host, $port),
            ];
        }
        
        // 3. ตรวจสอบสิทธิ์จริงจาก MySQL Data Dictionary ครอบคลุมทุกระดับ
        $privCheck = $this->verifyMysqlReadOnlyPrivileges($mysqli, $database);
        
        if (!$privCheck['has_select']) {
            $mysqli->close();
            return [
                'success'        => false,
                'mode'           => 'real',
                'server_version' => $serverVersion,
                'database'       => $database,
                'is_readonly'    => false,
                'privileges'     => $privCheck['effective_privileges'],
                'prohibited'     => $privCheck['prohibited_found'],
                'error'          => "ความปลอดภัยล้มเหลว: บัญชีที่เชื่อมต่อไม่มีสิทธิ์ SELECT บนฐานข้อมูล '{$database}' จึงไม่สามารถอ่านข้อมูล HOSxP ได้",
                'diagnostics'    => [
                    'queries_executed' => $privCheck['queries_executed'],
                    'raw_grants'       => $privCheck['raw_grants'],
                    'inspected_levels' => ['Global', 'Schema', 'Table', 'Column', 'Role'],
                ],
            ];
        }

        if (!$privCheck['is_readonly']) {
            $mysqli->close();
            $prohibitedList = implode(', ', $privCheck['prohibited_found']);
            return [
                'success'        => false,
                'mode'           => 'real',
                'server_version' => $serverVersion,
                'database'       => $database,
                'is_readonly'    => false,
                'privileges'     => $privCheck['effective_privileges'],
                'prohibited'     => $privCheck['prohibited_found'],
                'error'          => "ความปลอดภัยล้มเหลว (Security Violation): ตรวจพบสิทธิ์เขียนข้อมูล ({$prohibitedList}) บนฐานข้อมูล '{$database}' — ระบบ RxCore บังคับใช้เฉพาะบัญชี Read-only (SELECT เท่านั้น) เพื่อป้องกันการแก้ไขข้อมูล HOSxP",
                'diagnostics'    => [
                    'queries_executed' => $privCheck['queries_executed'],
                    'raw_grants'       => $privCheck['raw_grants'],
                    'inspected_levels' => ['Global', 'Schema', 'Table', 'Column', 'Role'],
                ],
            ];
        }
        
        // 4. ตรวจสอบตารางสำคัญของ HOSxP
        $tablesResult = $mysqli->query("SHOW TABLES");
        $allTables = [];
        if ($tablesResult) {
            while ($row = $tablesResult->fetch_row()) {
                $allTables[] = $row[0];
            }
            $tablesResult->free();
        }
        
        $foundHosxpTables = array_intersect($this->requiredHosxpTables, $allTables);
        $missingTables = array_diff($this->requiredHosxpTables, $allTables);
        
        if (!empty($missingTables)) {
            $warnings[] = '⚠️ ไม่พบตารางสำคัญ: ' . implode(', ', $missingTables) . ' — ตรวจสอบว่าเลือกฐานข้อมูล HOSxP ถูกต้อง';
        }
        
        $mysqli->close();
        
        return [
            'success'        => true,
            'mode'           => 'real',
            'server_version' => $serverVersion,
            'database'       => $database,
            'is_readonly'    => true,
            'privileges'     => $privCheck['effective_privileges'],
            'prohibited'     => [],
            'tables_found'   => count($allTables),
            'hosxp_tables'   => array_values($foundHosxpTables),
            'error'          => null,
            'warnings'       => $warnings,
            'diagnostics'    => [
                'queries_executed' => $privCheck['queries_executed'],
                'raw_grants'       => $privCheck['raw_grants'],
                'inspected_levels' => ['Global', 'Schema', 'Table', 'Column', 'Role'],
            ],
        ];
    }
    
    /**
     * ดึงและตรวจสอบสิทธิ์จาก MySQL Server จริง
     * ครอบคลุม: Global, Schema, Table, Column และสิทธิ์ที่สืบทอดจาก Role (MySQL 8+ / MariaDB 10+)
     */
    private function verifyMysqlReadOnlyPrivileges(\mysqli $mysqli, string $database): array {
        $queriesExecuted = [];
        $rawGrants = [];
        $catalog = [
            'global'             => [],
            'schema'             => [],
            'table'              => [],
            'column'             => [],
            'roles'              => [],
            'role_table_grants'  => [],
            'role_column_grants' => [],
        ];

        // 1. ดึงคำสั่ง GRANT จาก SHOW GRANTS FOR CURRENT_USER()
        $qGrants = "SHOW GRANTS FOR CURRENT_USER()";
        $queriesExecuted[] = $qGrants;
        $resGrants = @$mysqli->query($qGrants);
        if ($resGrants) {
            while ($row = $resGrants->fetch_row()) {
                $rawGrants[] = trim($row[0]);
            }
            $resGrants->free();
        }

        // 2. ตรวจสอบ Role สืบทอด (MySQL 8.0+ / MariaDB 10.0+)
        // ตรวจสอบ CURRENT_ROLE()
        $qCurrentRole = "SELECT CURRENT_ROLE()";
        $queriesExecuted[] = $qCurrentRole;
        $resCurrentRole = @$mysqli->query($qCurrentRole);
        if ($resCurrentRole) {
            while ($row = $resCurrentRole->fetch_row()) {
                if (!empty($row[0]) && $row[0] !== 'NONE') {
                    $catalog['roles'][] = trim($row[0]);
                }
            }
            $resCurrentRole->free();
        }

        // ตรวจสอบ APPLICABLE_ROLES ใน information_schema (หากรองรับ)
        try {
            $qRoles = "SELECT ROLE_NAME FROM information_schema.APPLICABLE_ROLES";
            $queriesExecuted[] = $qRoles;
            $resRoles = @$mysqli->query($qRoles);
            if ($resRoles) {
                while ($row = $resRoles->fetch_row()) {
                    $roleName = trim($row[0]);
                    $catalog['roles'][] = $roleName;
                    
                    // หากมี role ดึง grant statements ที่สืบทอดจาก role
                    // รองรับทั้ง MariaDB (FOR 'role') และ MySQL 8.0+ (FOR CURRENT_USER() USING 'role')
                    $resRoleG = null;
                    try {
                        $resRoleG = $mysqli->query("SHOW GRANTS FOR '{$roleName}'");
                    } catch (\Throwable) {}

                    if (!$resRoleG) {
                        try {
                            $resRoleG = $mysqli->query("SHOW GRANTS FOR CURRENT_USER() USING '{$roleName}'");
                        } catch (\Throwable) {}
                    }

                    if ($resRoleG) {
                        while ($rRow = $resRoleG->fetch_row()) {
                            $rawGrants[] = trim($rRow[0]);
                        }
                        $resRoleG->free();
                    }
                }
                $resRoles->free();
            }
        } catch (\Throwable) {
            // Server เวอร์ชันเก่าอาจไม่มีตาราง APPLICABLE_ROLES ให้ข้ามอย่างปลอดภัย
        }

        // 3. ตรวจสอบระดับ Global จาก information_schema.USER_PRIVILEGES
        try {
            $qGlobal = "SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES";
            $queriesExecuted[] = $qGlobal;
            $resGlobal = @$mysqli->query($qGlobal);
            if ($resGlobal) {
                while ($row = $resGlobal->fetch_row()) {
                    $catalog['global'][] = strtoupper(trim($row[0]));
                }
                $resGlobal->free();
            }
        } catch (\Throwable) {}

        // 4. ตรวจสอบระดับ Schema จาก information_schema.SCHEMA_PRIVILEGES
        try {
            $escapedDb = $mysqli->real_escape_string($database);
            $qSchema = "SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES WHERE TABLE_SCHEMA = '{$escapedDb}'";
            $queriesExecuted[] = $qSchema;
            $resSchema = @$mysqli->query($qSchema);
            if ($resSchema) {
                while ($row = $resSchema->fetch_row()) {
                    $catalog['schema'][] = strtoupper(trim($row[0]));
                }
                $resSchema->free();
            }
        } catch (\Throwable) {}

        // 5. ตรวจสอบระดับ Table จาก information_schema.TABLE_PRIVILEGES
        try {
            $escapedDb = $mysqli->real_escape_string($database);
            $qTable = "SELECT TABLE_NAME, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES WHERE TABLE_SCHEMA = '{$escapedDb}'";
            $queriesExecuted[] = $qTable;
            $resTable = @$mysqli->query($qTable);
            if ($resTable) {
                while ($row = $resTable->fetch_row()) {
                    $catalog['table'][] = [
                        'table'     => trim($row[0]),
                        'privilege' => strtoupper(trim($row[1])),
                    ];
                }
                $resTable->free();
            }
        } catch (\Throwable) {}

        // 6. ตรวจสอบระดับ Column จาก information_schema.COLUMN_PRIVILEGES
        try {
            $escapedDb = $mysqli->real_escape_string($database);
            $qCol = "SELECT TABLE_NAME, COLUMN_NAME, PRIVILEGE_TYPE FROM information_schema.COLUMN_PRIVILEGES WHERE TABLE_SCHEMA = '{$escapedDb}'";
            $queriesExecuted[] = $qCol;
            $resCol = @$mysqli->query($qCol);
            if ($resCol) {
                while ($row = $resCol->fetch_row()) {
                    $catalog['column'][] = [
                        'table'     => trim($row[0]),
                        'column'    => trim($row[1]),
                        'privilege' => strtoupper(trim($row[2])),
                    ];
                }
                $resCol->free();
            }
        } catch (\Throwable) {}

        // 7. ตรวจสอบ Role Table / Column Grants (MySQL 8.0+)
        try {
            $escapedDb = $mysqli->real_escape_string($database);
            $qRoleTable = "SELECT ROLE_NAME, TABLE_NAME, PRIVILEGE_TYPE FROM information_schema.ROLE_TABLE_GRANTS WHERE TABLE_SCHEMA = '{$escapedDb}'";
            $queriesExecuted[] = $qRoleTable;
            $resRT = @$mysqli->query($qRoleTable);
            if ($resRT) {
                while ($row = $resRT->fetch_row()) {
                    $catalog['role_table_grants'][] = [
                        'role'      => trim($row[0]),
                        'table'     => trim($row[1]),
                        'privilege' => strtoupper(trim($row[2])),
                    ];
                }
                $resRT->free();
            }
        } catch (\Throwable) {}

        // ส่งข้อมูลเข้า Engine ตรวจสอบสิทธิ์ทุกระดับ
        $evalResult = $this->evaluateAllPrivilegeLevels($rawGrants, $catalog, $database);
        $evalResult['queries_executed'] = $queriesExecuted;
        $evalResult['raw_grants']       = array_values(array_unique($rawGrants));

        return $evalResult;
    }

    /**
     * เครื่องมือประเมินสิทธิ์ครอบคลุมทุกระดับ (Global, Schema, Table, Column, Role)
     * ตัดสินจากสิทธิ์จริงอย่างเคร่งครัด
     */
    public function evaluateAllPrivilegeLevels(array $grants, array $catalog, string $database): array {
        $prohibitedFound = [];
        $effectivePrivileges = [];

        // รายการสิทธิ์การเขียนหรือสิทธิ์อันตรายที่ไม่อนุญาตโดยเด็ดขาด
        $prohibitedKeywords = [
            'ALL', 'ALL PRIVILEGES',
            'INSERT', 'UPDATE', 'DELETE',
            'DROP', 'ALTER', 'CREATE',
            'INDEX', 'ALTER ROUTINE', 'CREATE ROUTINE',
            'EXECUTE', 'TRIGGER', 'CREATE VIEW', 'SHOW VIEW',
            'CREATE TEMPORARY TABLES', 'LOCK TABLES',
            'GRANT OPTION', 'SUPER', 'FILE', 'RELOAD', 'SHUTDOWN', 'PROCESS'
        ];

        // --- ส่วนที่ 1: ตรวจสอบจาก SHOW GRANTS statements ---
        foreach ($grants as $stmt) {
            $cleanStmt = trim($stmt);

            // 1.1 ตรวจสอบ Role Assignment e.g. GRANT 'writer_role' TO ...
            if (preg_match('/GRANT\s+[\'"`]([^\'"`]+)[\'"`]\s+TO/i', $cleanStmt, $m) && !str_contains(strtoupper($cleanStmt), ' ON ')) {
                // มีการผูก Role
                continue;
            }

            // 1.2 ตรวจสอบ GRANT <privs> ON <target> TO ...
            if (preg_match('/GRANT\s+(.+?)\s+ON\s+([^\s]+)\s+TO/i', $cleanStmt, $m)) {
                $rawPrivPart = $m[1];
                $target = trim(str_replace('`', '', $m[2]));

                // แยกระดับของ Target
                $isGlobal = ($target === '*.*');
                $isSchema = (str_starts_with($target, $database . '.*') || $target === $database);
                $isTable  = (str_starts_with($target, $database . '.') && !str_ends_with($target, '.*'));

                if (!$isGlobal && !$isSchema && !$isTable) {
                    continue; // สิทธิ์ของ Database อื่น ไม่เกี่ยวข้อง
                }

                // สกัดชื่อ Privilege (รองรับทั้งธรรมดาและ Column list เช่น UPDATE (vstdate))
                preg_match_all('/\b([A-Z_]+(?:\s+[A-Z_]+)?)(?:\s*\(([^)]*)\))?/i', $rawPrivPart, $pm, PREG_SET_ORDER);
                foreach ($pm as $item) {
                    $privName = strtoupper(trim($item[1]));
                    $colList  = isset($item[2]) ? trim($item[2]) : '';

                    if ($privName === 'USAGE') {
                        continue;
                    }

                    $effectivePrivileges[] = $privName;

                    if (in_array($privName, $prohibitedKeywords, true)) {
                        if ($colList !== '') {
                            $prohibitedFound[] = "{$privName} [Column: {$target}({$colList})]";
                        } elseif ($isTable) {
                            $prohibitedFound[] = "{$privName} [Table: {$target}]";
                        } elseif ($isSchema) {
                            $prohibitedFound[] = "{$privName} [Schema: {$target}]";
                        } elseif ($isGlobal) {
                            $prohibitedFound[] = "{$privName} [Global: *.*]";
                        }
                    }
                }
            }
        }

        // --- ส่วนที่ 2: ตรวจสอบจาก information_schema Catalog ---
        // 2.1 Global Level (USER_PRIVILEGES)
        foreach ($catalog['global'] ?? [] as $gp) {
            $effectivePrivileges[] = $gp;
            if (in_array($gp, $prohibitedKeywords, true)) {
                $prohibitedFound[] = "{$gp} [Global Catalog: USER_PRIVILEGES]";
            }
        }

        // 2.2 Schema Level (SCHEMA_PRIVILEGES)
        foreach ($catalog['schema'] ?? [] as $sp) {
            $effectivePrivileges[] = $sp;
            if (in_array($sp, $prohibitedKeywords, true)) {
                $prohibitedFound[] = "{$sp} [Schema Catalog: {$database}]";
            }
        }

        // 2.3 Table Level (TABLE_PRIVILEGES)
        foreach ($catalog['table'] ?? [] as $tp) {
            $tbl = $tp['table'] ?? 'unknown';
            $prv = $tp['privilege'] ?? '';
            $effectivePrivileges[] = $prv;
            if (in_array($prv, $prohibitedKeywords, true)) {
                $prohibitedFound[] = "{$prv} [Table Catalog: {$database}.{$tbl}]";
            }
        }

        // 2.4 Column Level (COLUMN_PRIVILEGES)
        foreach ($catalog['column'] ?? [] as $cp) {
            $tbl = $cp['table'] ?? 'unknown';
            $col = $cp['column'] ?? 'unknown';
            $prv = $cp['privilege'] ?? '';
            $effectivePrivileges[] = $prv;
            if (in_array($prv, $prohibitedKeywords, true)) {
                $prohibitedFound[] = "{$prv} [Column Catalog: {$database}.{$tbl}.{$col}]";
            }
        }

        // 2.5 Role Grants Level (ROLE_TABLE_GRANTS & ROLE_COLUMN_GRANTS)
        foreach ($catalog['role_table_grants'] ?? [] as $rt) {
            $rName = $rt['role'] ?? 'role';
            $tbl   = $rt['table'] ?? 'unknown';
            $prv   = $rt['privilege'] ?? '';
            $effectivePrivileges[] = $prv;
            if (in_array($prv, $prohibitedKeywords, true)) {
                $prohibitedFound[] = "{$prv} [Role Table: role '{$rName}' on {$database}.{$tbl}]";
            }
        }

        foreach ($catalog['role_column_grants'] ?? [] as $rc) {
            $rName = $rc['role'] ?? 'role';
            $tbl   = $rc['table'] ?? 'unknown';
            $col   = $rc['column'] ?? 'unknown';
            $prv   = $rc['privilege'] ?? '';
            $effectivePrivileges[] = $prv;
            if (in_array($prv, $prohibitedKeywords, true)) {
                $prohibitedFound[] = "{$prv} [Role Column: role '{$rName}' on {$database}.{$tbl}.{$col}]";
            }
        }

        $effectivePrivileges = array_values(array_unique(array_filter($effectivePrivileges)));
        $prohibitedFound    = array_values(array_unique($prohibitedFound));

        // ตรวจสอบว่ามีสิทธิ์ SELECT หรือไม่
        $hasSelect = in_array('SELECT', $effectivePrivileges, true);

        // บัญชีเป็น Read-only อย่างแท้จริงเมื่อ:
        // มีสิทธิ์ SELECT และปราศจากสิทธิ์เขียนในทุกระดับ (Global, Schema, Table, Column, Role)
        $isStrictReadOnly = $hasSelect && empty($prohibitedFound);

        return [
            'is_readonly'          => $isStrictReadOnly,
            'has_select'           => $hasSelect,
            'effective_privileges' => $effectivePrivileges,
            'prohibited_found'     => $prohibitedFound,
        ];
    }
    
    /**
     * ตรวจสอบข้อมูล Config เบื้องต้น
     */
    private function validateConfig(array $config): array {
        if (empty($config['hosxp_host'])) {
            return ['valid' => false, 'error' => 'กรุณาระบุ Host ของเซิร์ฟเวอร์ HOSxP MySQL'];
        }
        
        $port = (int)($config['hosxp_port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            return ['valid' => false, 'error' => 'Port ต้องอยู่ระหว่าง 1–65535'];
        }
        
        if (empty($config['hosxp_database'])) {
            return ['valid' => false, 'error' => 'กรุณาระบุชื่อฐานข้อมูล HOSxP (เช่น hos)'];
        }
        
        if (empty($config['hosxp_username'])) {
            return ['valid' => false, 'error' => 'กรุณาระบุ Username สำหรับเชื่อมต่อ HOSxP MySQL'];
        }
        
        $hcode = $config['hcode'] ?? '';
        if (strlen($hcode) !== 5 || !ctype_digit($hcode)) {
            return ['valid' => false, 'error' => 'รหัสสถานพยาบาล (hcode) ต้องเป็นตัวเลข 5 หลัก'];
        }
        
        return ['valid' => true, 'error' => null];
    }
    
    /**
     * แปลงข้อผิดพลาด MySQL เป็นภาษาไทยที่เข้าใจง่าย
     * ห้ามแสดง Password ในข้อความผิดพลาดโดยเด็ดขาด
     */
    private function translateMysqlError(int $code, string $message, string $host, int $port): string {
        $cleanMsg = preg_replace('/password.*/i', 'password [REDACTED]', $message);
        if (stripos($message, 'timed out') !== false || stripos($message, 'not properly respond') !== false || stripos($message, 'Connection refused') !== false || $code === 2002) {
            return "ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ {$host}:{$port} ได้ (Timeout/Refused) — ตรวจสอบว่า MySQL Server กำลังทำงานและไฟร์วอลล์อนุญาตพอร์ต {$port}";
        }
        return match (true) {
            $code === 2003 => "ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ {$host}:{$port} ได้ — ตรวจสอบ IP Address และ Port ให้ถูกต้อง",
            $code === 1045 => "Username หรือ Password ไม่ถูกต้อง — ตรวจสอบข้อมูลเข้าสู่ระบบอีกครั้ง",
            $code === 1049 => "ไม่พบฐานข้อมูลที่ระบุบนเซิร์ฟเวอร์ — ตรวจสอบชื่อฐานข้อมูล",
            $code === 1044 => "ไม่มีสิทธิ์เข้าถึงฐานข้อมูลที่ระบุ — ติดต่อผู้ดูแลระบบ",
            default        => "เกิดข้อผิดพลาดในการเชื่อมต่อ (Code: {$code}) — " . $cleanMsg,
        };
    }
}
