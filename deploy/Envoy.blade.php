@setup
    try {
        $env_file = '.env';
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__, $env_file);
        $dotenv->load();
    } catch (Exception $e) {
        throw new Exception("Failed to load $env_file: " . $e->getMessage());
    }
    $server_ip = env('SERVER_IP');
    $ssh_key_path = env('SSH_KEY_PATH');
    $ssh_port = env('SSH_PORT');
    $ssh_port_new = env('SSH_PORT_NEW');
    $local_host = env('ENVOY_LOCAL_HOST');

    if (empty($local_host)) {
        $local_host = 'local';
    }

    if (in_array($local_host, ['127.0.1.1', '::1'], true)) {
        $local_host = 'local';
    }

    if (empty($server_ip) || empty($ssh_key_path)) {
        throw new Exception("SERVER_IP or SSH_KEY_PATH is not set. SERVER_IP: $server_ip, SSH_KEY_PATH: $ssh_key_path");
    }
    $requiredVars = [
        'APP_URL', 'TRAEFIK_ACME_EMAIL', 'DB_DATABASE', 'DB_USERNAME'
    ];
    function getEnvVar($file, $key) {
        $content = file_get_contents(__DIR__ . '/' . $file);
        if (!$content) return null;
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, $key . '=') === 0) {
                $value = trim(substr($line, strlen($key . '=')), '"\' ');
                return $value ?: null;
            }
        }
        return null;
    }
    function fetchRemoteProjects($server_ip, $ssh_port_new, $ssh_port, $ssh_key_path, $sites_root, $reverse_proxy_folder_name) {
        $remoteCommand = "for d in " . escapeshellarg($sites_root) . "/*; do "
            . "[ -d \"\$d\" ] || continue; "
            . "name=\"$(basename \"\$d\")\"; "
            . "[ \"\$name\" = " . escapeshellarg($reverse_proxy_folder_name) . " ] && continue; "
            . "case \"\$name\" in *.backup|*.removed.*) continue ;; esac; "
            . "if [ -f \"\$d/docker-compose.yml\" ] || [ -f \"\$d/compose.yml\" ] || [ -f \"\$d/compose.yaml\" ]; then "
            . "echo \"\$name\"; "
            . "fi; "
            . "done | sort -u";

        $ports = array_values(array_unique(array_filter([
            trim((string) $ssh_port_new),
            trim((string) $ssh_port),
        ], static function ($v) {
            return $v !== '';
        })));

        $users = ['deploy', 'root'];
        $projects = [];
        foreach ($users as $user) {
            foreach ($ports as $port) {
                $sshParts = [
                    'ssh',
                    '-o', 'BatchMode=yes',
                    '-o', 'ConnectTimeout=6',
                    '-o', 'StrictHostKeyChecking=no',
                    '-p', escapeshellarg($port),
                    '-i', escapeshellarg($ssh_key_path),
                    $user . '@' . $server_ip,
                    escapeshellarg($remoteCommand),
                ];

                $command = implode(' ', $sshParts) . ' 2>/dev/null';
                $rawOutput = shell_exec($command);

                if (!is_string($rawOutput) || trim($rawOutput) === '') {
                    continue;
                }

                $outputLines = explode("\n", $rawOutput);
                foreach ($outputLines as $line) {
                    $project = trim((string) $line);
                    if ($project === '') {
                        continue;
                    }
                    if (preg_match('/(\.backup$|\.removed\.)/', $project)) {
                        continue;
                    }
                    $projects[] = $project;
                }
            }
        }

        if (empty($projects)) {
            return [];
        }

        sort($projects);
        return array_values(array_unique($projects));
    }

    function fetchRemoteProjectsFallback($server_ip, $ssh_port_new, $ssh_port, $ssh_key_path, $sites_root, $reverse_proxy_folder_name) {
        $remoteCommand = "for d in " . escapeshellarg($sites_root) . "/*; do "
            . "[ -d \"\$d\" ] || continue; "
            . "name=\"$(basename \"\$d\")\"; "
            . "[ \"\$name\" = " . escapeshellarg($reverse_proxy_folder_name) . " ] && continue; "
            . "case \"\$name\" in *.backup|*.removed.*) continue ;; esac; "
            . "if [ -f \"\$d/docker-compose.yml\" ] || [ -f \"\$d/compose.yml\" ] || [ -f \"\$d/compose.yaml\" ]; then "
            . "echo \"\$name\"; "
            . "fi; "
            . "done | sort -u";

        $ports = array_values(array_unique(array_filter([
            trim((string) $ssh_port_new),
            trim((string) $ssh_port),
        ], static function ($v) {
            return $v !== '';
        })));

        foreach ($ports as $port) {
            $directCmd = 'ssh -o BatchMode=yes -o ConnectTimeout=6 -o StrictHostKeyChecking=no'
                . ' -p ' . escapeshellarg($port)
                . ' -i ' . escapeshellarg($ssh_key_path)
                . ' ' . escapeshellarg('deploy@' . $server_ip)
                . ' ' . escapeshellarg($remoteCommand)
                . ' 2>/dev/null';

            $rawOutput = shell_exec($directCmd);
            if (!is_string($rawOutput) || trim($rawOutput) === '') {
                continue;
            }

            $projects = array_values(array_filter(array_map('trim', explode("\n", $rawOutput)), static function ($item) {
                return $item !== '';
            }));

            if (!empty($projects)) {
                sort($projects);
                return array_values(array_unique($projects));
            }
        }

        return [];
    }

    function parseProjectSelectionCsv($selection, $available_projects) {
        $tokens = array_values(array_filter(array_map('trim', explode(',', $selection)), static function ($item) {
            return $item !== '';
        }));

        if (empty($tokens)) {
            return [];
        }

        $selected = [];
        foreach ($tokens as $token) {
            if (ctype_digit($token)) {
                $index = (int) $token;
                if ($index < 1 || $index > count($available_projects)) {
                    throw new Exception("Unknown project index: {$token}");
                }
                $selected[] = $available_projects[$index - 1];
                continue;
            }

            if (!in_array($token, $available_projects, true)) {
                throw new Exception("Unknown project slug: {$token}");
            }
            $selected[] = $token;
        }

        return array_values(array_unique($selected));
    }
    $host = getEnvVar('.env.production', 'TRAEFIK_HOST');

    $path = parse_url($host, PHP_URL_PATH);

    echo "Parsed path: {$path}\n";
    $site_name = $host;
    echo "Determined SITE_NAME: {$site_name}\n";
    $prefix = strtolower(preg_replace('/[^a-z0-9]/', '', $host));
    $container_prefix = $prefix . '-';
    $volume_prefix = $prefix . '_';

    echo "$site_name";
    echo "CONTAINER_PREFIX: {$container_prefix}\n";
    echo "VOLUME_PREFIX: {$volume_prefix}\n";
    $remote_html_path = "/var/www/{$site_name}";
    echo "Running in MULTI-SITE mode (site: {$site_name})\n";

    $force_deploy_haproxy_raw = env('FORCE_DEPLOY_HAPROXY', false);
    $force_deploy_haproxy = in_array($force_deploy_haproxy_raw, [true, 'true', '1', 'TRUE'], true)
        ? 'true'
        : 'false';

    echo "FORCE_DEPLOY_HAPROXY: {$force_deploy_haproxy}\n";
    $sites_root = '/var/www';
    $reverse_proxy_folder_name = 'reverse-proxy';

    // Docker cleanup runtime config (can be overridden per run via shell env vars)
    $docker_cleanup_scope = strtolower(trim((string) (getenv('DOCKER_CLEAN_SCOPE') ?: env('DOCKER_CLEAN_SCOPE', 'all'))));
    $docker_cleanup_targets = strtolower(trim((string) (getenv('DOCKER_CLEAN_TARGETS') ?: env('DOCKER_CLEAN_TARGETS', 'containers,images,networks,volumes'))));
    $docker_cleanup_projects = trim((string) (getenv('DOCKER_CLEAN_PROJECTS') ?: env('DOCKER_CLEAN_PROJECTS', $site_name)));
    $docker_cleanup_images_mode = strtolower(trim((string) (getenv('DOCKER_CLEAN_IMAGES_MODE') ?: env('DOCKER_CLEAN_IMAGES_MODE', 'all'))));

    $remove_project_slug = trim((string) (getenv('REMOVE_PROJECT') ?: env('REMOVE_PROJECT', $site_name)));
    $remove_project_confirmed = 'false';

    $argv = $_SERVER['argv'] ?? [];
    $run_target = (($argv[1] ?? null) === 'run') ? ($argv[2] ?? null) : null;
    $is_interactive_terminal = true;
    if (defined('STDIN') && function_exists('posix_isatty')) {
        $is_interactive_terminal = @posix_isatty(STDIN);
    }

    if ($run_target === 'remove_project') {
        if ($is_interactive_terminal) {
            $available_projects = fetchRemoteProjects($server_ip, $ssh_port_new, $ssh_port, $ssh_key_path, $sites_root, $reverse_proxy_folder_name);
            if (empty($available_projects)) {
                $available_projects = fetchRemoteProjectsFallback($server_ip, $ssh_port_new, $ssh_port, $ssh_key_path, $sites_root, $reverse_proxy_folder_name);
            }

            $remove_prompt = "Project to remove [{$site_name}] (Enter/y=default, number, or slug): ";
            if (!empty($available_projects)) {
                $list_lines = [];
                foreach ($available_projects as $idx => $project_slug) {
                    $mark = ($project_slug === $site_name) ? ' (default)' : '';
                    $list_lines[] = '  ' . ($idx + 1) . ") {$project_slug}{$mark}";
                }
                $remove_prompt = "Available projects on server:\n"
                    . implode("\n", $list_lines)
                    . "\n"
                    . $remove_prompt;
            } else {
                $remove_prompt = "No project list detected (server={$server_ip}, port={$ssh_port_new}/{$ssh_port}).\n"
                    . $remove_prompt;
            }

            $input_project = trim((string) readline($remove_prompt));
            $input_project_normalized = strtolower($input_project);

            if ($input_project === '' || in_array($input_project_normalized, ['y', 'yes', 'default'], true)) {
                $remove_project_slug = $site_name;
            } elseif (in_array($input_project_normalized, ['n', 'no'], true)) {
                throw new Exception("Project removal cancelled by user");
            } elseif (ctype_digit($input_project) && !empty($available_projects)) {
                $selected_index = (int) $input_project;
                if ($selected_index < 1 || $selected_index > count($available_projects)) {
                    throw new Exception("Unknown project index: {$input_project}");
                }
                $remove_project_slug = $available_projects[$selected_index - 1];
            } else {
                if (!empty($available_projects) && !in_array($input_project, $available_projects, true)) {
                    throw new Exception("Unknown project slug: {$input_project}");
                }
                $remove_project_slug = $input_project;
            }

            $confirm = strtolower(trim((string) readline("Type DELETE to confirm removing '{$remove_project_slug}': ")));
            if ($confirm !== 'delete') {
                throw new Exception("Project removal cancelled by user");
            }

            $remove_project_confirmed = 'true';
        } else {
            $confirm_flag = strtolower(trim((string) (getenv('REMOVE_PROJECT_CONFIRM') ?: env('REMOVE_PROJECT_CONFIRM', ''))));
            if ($remove_project_slug === '' || !in_array($confirm_flag, ['1', 'true', 'yes', 'delete'], true)) {
                throw new Exception("remove_project in non-interactive mode requires REMOVE_PROJECT and REMOVE_PROJECT_CONFIRM=DELETE");
            }
            $remove_project_confirmed = 'true';
        }
    }

    if ($run_target === 'cleanup_docker' && $is_interactive_terminal) {
        $scope_choice = trim((string) readline("Cleanup scope [all/current/projects] (default: all): "));
        if ($scope_choice !== '' && in_array($scope_choice, ['all', 'current', 'projects'], true)) {
            $docker_cleanup_scope = $scope_choice;
        }

        $targets_choice = trim((string) readline("Targets CSV [containers,images,networks,volumes] (default: {$docker_cleanup_targets}): "));
        if ($targets_choice !== '') {
            $docker_cleanup_targets = strtolower($targets_choice);
        }

        if (strpos($docker_cleanup_targets, 'images') !== false) {
            $images_mode_choice = trim((string) readline("Images mode [all/dangling] (default: {$docker_cleanup_images_mode}): "));
            if ($images_mode_choice !== '' && in_array($images_mode_choice, ['all', 'dangling'], true)) {
                $docker_cleanup_images_mode = $images_mode_choice;
            }
        }

        if ($docker_cleanup_scope === 'projects') {
            $available_projects = fetchRemoteProjects($server_ip, $ssh_port_new, $ssh_port, $ssh_key_path, $sites_root, $reverse_proxy_folder_name);
            if (empty($available_projects)) {
                $available_projects = fetchRemoteProjectsFallback($server_ip, $ssh_port_new, $ssh_port, $ssh_key_path, $sites_root, $reverse_proxy_folder_name);
            }

            $projects_prompt = "Projects CSV (numbers or slugs, Enter=current): ";
            if (!empty($available_projects)) {
                $list_lines = [];
                foreach ($available_projects as $idx => $project_slug) {
                    $mark = ($project_slug === $site_name) ? ' (current)' : '';
                    $list_lines[] = '  ' . ($idx + 1) . ") {$project_slug}{$mark}";
                }

                $projects_prompt = "Available projects on server:\n"
                    . implode("\n", $list_lines)
                    . "\n"
                    . $projects_prompt;

                $projects_choice = trim((string) readline($projects_prompt));
                if ($projects_choice === '') {
                    $docker_cleanup_projects = $site_name;
                } else {
                    $selected_projects = parseProjectSelectionCsv($projects_choice, $available_projects);
                    if (empty($selected_projects)) {
                        throw new Exception("cleanup_docker scope=projects requires project list");
                    }
                    $docker_cleanup_projects = implode(',', $selected_projects);
                }
            } else {
                $projects_prompt = "No project list detected (server={$server_ip}, port={$ssh_port_new}/{$ssh_port}).\n"
                    . "Projects CSV (example: askaiforit.com,creem.nidmo.com): ";
                $projects_choice = trim((string) readline($projects_prompt));
                if ($projects_choice === '') {
                    throw new Exception("cleanup_docker scope=projects requires project list");
                }
                $docker_cleanup_projects = $projects_choice;
            }
        }

        $confirm_cleanup = strtolower(trim((string) readline("Type CLEAN to confirm docker cleanup: ")));
        if ($confirm_cleanup !== 'clean') {
            throw new Exception("Docker cleanup cancelled by user");
        }
    }


    $tempDir = '/tmp/laravel-deploy-' . $site_name . '-' . time();
    $local_project_root = __DIR__;
@endsetup

@servers([
    'local' => $local_host,
    'web' => "root@$server_ip -p $ssh_port -i $ssh_key_path",
    'web_changed' => "root@$server_ip -p $ssh_port_new -i $ssh_key_path",
    'web_new' => "deploy@$server_ip -p $ssh_port_new -i $ssh_key_path"
])

@story('deploy')
    configure_sudoers
    create_temp_dir
    sync_files
    prepare_app_dir
    calculate_workers
    deploy_haproxy_config
    deploy_haproxy_compose
    update_haproxy_volumes
    generate_production_compose
    prepare_secrets_dir
    backup_server_secrets
    generate_server_secrets
    upload_cf_token
    sync_reverse_proxy_network_range
    cert-manager-start
    ensure_haproxy_running
    local_assets_building
    deploy_haproxy
    deploy_app_zero_downtime
    finalize_deploy
    docker-cleanup
    cleanup_secret_backups
    remove_sudoers
@endstory

@story('blockip')
    configure_sudoers
    deploy_haproxy_config
    calculate_workers
    cert-manager-start
    update_haproxy_volumes
    deploy_haproxy
    remove_sudoers
@endstory

@story('server_setup')
    check_requirements
    clean_ssh_keys
    validate_server_access
    server_setup
    change_ssh_port
    validate_new_ssh_access
    update_ssh_port
    install_docker
    server_reboot
@endstory

@story('initial_deploy')
    configure_sudoers
    create_temp_dir
    create_reverse_proxy_dir
    sync_files
    prepare_app_dir
    calculate_workers
    deploy_haproxy_config
    deploy_haproxy_compose
    update_haproxy_volumes
    generate_production_compose
    prepare_secrets_dir
    backup_server_secrets
    generate_server_secrets
    upload_cf_token
    sync_reverse_proxy_network_range
    cert-manager-start
    ensure_haproxy_running
    local_assets_building
    start_containers
    deploy_haproxy
    prepare_docker_volumes
    tune_postgres
    dump_local_db
    copy_db_dump
    restore_db_dump_on_server
    create_seaweedfs_archive
    extract_seaweedfs_archive
    finalize_deploy
    setup_backup
    configure_backup
    health_checks
    monitor_restarts
    cleanup_secret_backups
    remove_sudoers
@endstory

@story('remove_project')
    configure_sudoers
    remove_project_stack
    update_haproxy_volumes
    deploy_haproxy
    remove_sudoers
@endstory

@story('cleanup_secrets')
    configure_sudoers
    cleanup_server_secrets
    remove_sudoers
@endstory

@story('cleanup_docker')
    configure_sudoers
    docker-cleanup
    remove_sudoers
@endstory

@error
    $timestamp = date('Y-m-d H:i:s');
    echo "\033[0;31m[$timestamp] Deployment failed: {$message}\033[0m\n";

    echo "\033[0;33m[$timestamp] Attempting to restore secret backups...\033[0m\n";

    exec('php vendor/bin/envoy run restore_server_secrets', $restoreOutput, $restoreExitCode);

    if ($restoreExitCode !== 0) {
        echo "\033[0;31m[$timestamp] Failed to restore secret backups (exit code: {$restoreExitCode})\033[0m\n";
        if (!empty($restoreOutput)) {
            echo "\033[0;31m[$timestamp] Output: " . implode(' | ', $restoreOutput) . "\033[0m\n";
        }
    } else {
        echo "\033[0;32m[$timestamp] Secret backups restored successfully.\033[0m\n";
        if (!empty($restoreOutput)) {
            echo "\033[0;32m[$timestamp] Output: " . implode(' | ', $restoreOutput) . "\033[0m\n";
        }
    }

    echo "\033[0;33m[$timestamp] Attempting to remove sudoers...\033[0m\n";

    exec('php vendor/bin/envoy run remove_sudoers', $output, $exitCode);

    if ($exitCode !== 0) {
        echo "\033[0;31m[$timestamp] Failed to remove sudoers (exit code: {$exitCode})\033[0m\n";
        if (!empty($output)) {
            echo "\033[0;31m[$timestamp] Output: " . implode(' | ', $output) . "\033[0m\n";
        }
    } else {
        echo "\033[0;32m[$timestamp] Sudoers removed successfully.\033[0m\n";
        if (!empty($output)) {
            echo "\033[0;32m[$timestamp] Output: " . implode(' | ', $output) . "\033[0m\n";
        }
    }
@enderror

@task('check_requirements', ['on' => 'local'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Checking requirements...\033[0m"

    if [ ! -f ./.env.production ]; then
        echo -e "\033[0;31m[$(date +'%Y-%m-%d %H:%M:%S')] .env.production file is missing!\033[0m"
        exit 1
    fi

    test -f scripts/deploy/compose-stack.sh || { echo "Error: scripts/deploy/compose-stack.sh not found"; exit 1; }

    required_vars=(APP_URL TRAEFIK_ACME_EMAIL)

    if bash scripts/deploy/compose-stack.sh has-service deploy/docker-compose/docker-compose.prod.yml postgres >/dev/null 2>&1; then
        required_vars+=(DB_DATABASE DB_USERNAME)
    fi

    if bash scripts/deploy/compose-stack.sh list-blue-green deploy/docker-compose/docker-compose.prod.yml blue | grep -q '^seaweedfs-blue$'; then
        required_vars+=(SEAWEEDFS_URL SEAWEEDFS_ENDPOINT SEAWEEDFS_BUCKET SEAWEEDFS_ACCESS_KEY SEAWEEDFS_SECRET_KEY)
    fi

    for var in "${required_vars[@]}"; do
        if ! grep -q "^${var}=" ./.env.production; then
            echo -e "\033[0;31m[$(date +'%Y-%m-%d %H:%M:%S')] Missing ${var} in ./.env.production!\033[0m"
            exit 1
        fi
    done

    if [ ! -f "{{ $ssh_key_path }}" ]; then
        echo -e "\033[0;31m[$(date +'%Y-%m-%d %H:%M:%S')] SSH key not found at {{ $ssh_key_path }}!\033[0m"
        exit 1
    fi

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Requirements check passed ✓\033[0m"
@endtask

@task('clean_ssh_keys', ['on' => 'local'])
    echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Cleaning old SSH host keys (for VPS recreation)...\033[0m"

    ssh-keygen -f "$HOME/.ssh/known_hosts" -R "{{ env('SERVER_IP') }}" 2>/dev/null || true

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] SSH host keys cleaned ✓\033[0m"
@endtask

@task('validate_server_access', ['on' => 'local'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Validating server access...\033[0m"

    if ! ssh -i "{{ env('SSH_KEY_PATH') }}" -p {{ env('SSH_PORT', 22) }} -o ConnectTimeout=10 -o StrictHostKeyChecking=no root@"{{ env('SERVER_IP') }}" "echo 'Server accessible'" >/dev/null 2>&1; then
        echo -e "\033[0;31m[ERROR] Cannot connect to server {{ env('SERVER_IP') }} on port {{ env('SSH_PORT', 22) }}. Check SSH configuration.\033[0m"
        exit 1
    fi

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Server access validated ✓\033[0m"
@endtask

@task('server_setup', ['on' => 'web'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Stage 1: Server setup and security hardening...\033[0m"
    {{ file_get_contents('deploy/scripts/01-server-setup.sh') }}
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Server setup completed ✓\033[0m"
@endtask

@task('change_ssh_port', ['on' => 'web'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Stage 2: Changing SSH port to {{ $ssh_port_new }}...\033[0m"
    {{ str_replace(
        ['***SSH_PORT***', '***SSH_PORT_NEW***'],
        [$ssh_port, $ssh_port_new],
        file_get_contents('deploy/scripts/02-change-ssh-port.sh')
    ) }}

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] SSH port change completed ✓\033[0m"
@endtask

@task('validate_new_ssh_access', ['on' => 'local'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Validating new SSH access on port 20018...\033[0m"

    if ! ssh -i "{{ env('SSH_KEY_PATH') }}" -p {{ env('SSH_PORT_NEW') }} -o ConnectTimeout=10 -o StrictHostKeyChecking=no deploy@"{{ env('SERVER_IP') }}" "echo 'New SSH accessible'" >/dev/null 2>&1; then
        echo -e "\033[0;31m[ERROR] Cannot connect to server {{ env('SERVER_IP') }} on port 20018. Check SSH configuration.\033[0m"
        exit 1
    fi

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] New SSH access validated ✓\033[0m"
@endtask

@task('update_ssh_port', ['on' => 'local'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Updating SSH_PORT in .env.production...\033[0m"

    sed -i 's/^SSH_PORT=.*/SSH_PORT={{ $ssh_port_new }}/' .env.production

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Cleaning old SSH host keys...\033[0m"

    ssh-keygen -f "$HOME/.ssh/known_hosts" -R "[{{ $server_ip }}]:{{ $ssh_port_old }}" 2>/dev/null || true
    ssh-keygen -f "$HOME/.ssh/known_hosts" -R "[{{ $server_ip }}]:{{ $ssh_port_new }}" 2>/dev/null || true

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] SSH_PORT updated to {{ $ssh_port_new }} ✓\033[0m"
@endtask


@task('install_docker', ['on' => 'web_new'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Stage 2: Installing Docker and dependencies...\033[0m"
    {{ file_get_contents('deploy/scripts/03-docker-install.sh') }}
@endtask

@task('server_reboot', ['on' => 'web_new'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Rebooting server to apply changes...\033[0m"
    sudo reboot
@endtask

@task('configure_logrotate', ['on' => 'web_new'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Stage 3: Configuring log rotation...\033[0m"
    {{ file_get_contents('deploy/scripts/04-logrotate.sh') }}
    # Add per-site logrotate
    cat > /etc/logrotate.d/${SITE_NAME}-docker << EOF
{{ $remote_html_path }}/storage/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
EOF
@endtask

@task('create_temp_dir', ['on' => 'web_new'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Creating temporary deployment directory...\033[0m"

    mkdir -p {{ $tempDir }}

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Temporary directory created: {{ $tempDir }} ✓\033[0m"
@endtask

@task('create_reverse_proxy_dir', ['on' => 'web_changed'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Creating reverse proxy directory...\033[0m"

    reverse_proxy_path="{{ $sites_root }}/{{ $reverse_proxy_folder_name }}"

    mkdir -p "$reverse_proxy_path"
    chown deploy:deploy "$reverse_proxy_path"
    chmod 755 "$reverse_proxy_path"
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Reverse proxy directory created: $reverse_proxy_path ✓\033[0m"
@endtask

@task('sync_files', ['on' => 'local'])
    set -e
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Stage 4: Syncing files to server...\033[0m"

    test -f deploy/docker-compose/docker-compose.prod.yml || { echo "Error: deploy/docker-compose/docker-compose.prod.yml not found locally"; exit 1; }
    test -f deploy/docker-compose/docker-compose.haproxy.prod.yml || { echo "Error: deploy/docker-compose/docker-compose.haproxy.prod.yml not found locally"; exit 1; }
    test -d deploy/docker-compose/docker || { echo "Error: deploy/docker-compose/docker directory not found locally"; exit 1; }
    test -f scripts/deploy/compose-stack.sh || { echo "Error: scripts/deploy/compose-stack.sh not found locally"; exit 1; }
    test -f deploy/configs/configure.sh || { echo "Error: deploy/configs/configure.sh not found locally"; exit 1; }
    test -f deploy/configs/start-queue.sh || { echo "Error: deploy/configs/start-queue.sh not found locally"; exit 1; }
    test -f .env.production || { echo "Error: .env.production not found locally"; exit 1; }

    rsync -avz \
        --exclude-from='.gitignore' \
        --exclude='/core' \
        --exclude='docker-compose.yml' \
        --exclude='docker-compose.prod.yml' \
        --exclude='docker-compose.haproxy.prod.yml' \
        --exclude='.git' \
        --exclude='node_modules' \
        --exclude='vendor' \
        --exclude='bootstrap/cache/*' \
        --exclude='storage/logs/*' \
        --exclude='storage/checkpoints/' \
        --exclude='storage/debugbar/' \
        --exclude='storage/framework/cache/*' \
        --exclude='storage/framework/sessions/*' \
        --exclude='storage/framework/views/*' \
        --exclude='storage/app/private/*' \
        --exclude='storage/app/public/*' \
        --exclude='.env*' \
        --exclude='deploy/' \
        --exclude='.config' \
        --exclude='docker/haproxy' \
        -e "ssh -i '{{ env('SSH_KEY_PATH') }}' -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no" \
        ./ {{ 'deploy@' . env('SERVER_IP') }}:{{ $tempDir }}/ || { echo "Error: Failed to rsync project files"; exit 1; }

    ssh -i {{ env('SSH_KEY_PATH') }} -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no {{ 'deploy@' . env('SERVER_IP') }} "mkdir -p {{ $tempDir }}/scripts/deploy {{ $tempDir }}/docker" || { echo "Error: Failed to create required directories on remote server"; exit 1; }

    rsync -avz \
        -e "ssh -i '{{ env('SSH_KEY_PATH') }}' -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no" \
        deploy/docker-compose/docker/ {{ 'deploy@' . env('SERVER_IP') }}:{{ $tempDir }}/docker/ || { echo "Error: Failed to copy deploy/docker-compose/docker"; exit 1; }

    scp -i {{ env('SSH_KEY_PATH') }} -P {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no deploy/docker-compose/docker-compose.prod.yml {{ 'deploy@' . env('SERVER_IP') }}:{{ $tempDir }}/docker-compose.yml || { echo "Error: Failed to copy docker-compose.yml"; exit 1; }
    scp -i {{ env('SSH_KEY_PATH') }} -P {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no deploy/docker-compose/docker-compose.prod.yml {{ 'deploy@' . env('SERVER_IP') }}:{{ $tempDir }}/docker-compose.prod.yml || { echo "Error: Failed to copy docker-compose.prod.yml"; exit 1; }
    scp -i {{ env('SSH_KEY_PATH') }} -P {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no scripts/deploy/compose-stack.sh {{ 'deploy@' . env('SERVER_IP') }}:{{ $tempDir }}/scripts/deploy/compose-stack.sh || { echo "Error: Failed to copy scripts/deploy/compose-stack.sh"; exit 1; }
    scp -i {{ env('SSH_KEY_PATH') }} -P {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no deploy/configs/configure.sh {{ 'deploy@' . env('SERVER_IP') }}:{{ $tempDir }}/docker/configure.sh || { echo "Error: Failed to copy configure.sh"; exit 1; }
    scp -i {{ env('SSH_KEY_PATH') }} -P {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no deploy/configs/start-queue.sh {{ 'deploy@' . env('SERVER_IP') }}:{{ $tempDir }}/docker/start-queue.sh || { echo "Error: Failed to copy start-queue.sh"; exit 1; }
    scp -i {{ env('SSH_KEY_PATH') }} -P {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no .env.production {{ 'deploy@' . env('SERVER_IP') }}:{{ $tempDir }}/.env || { echo "Error: Failed to copy .env"; exit 1; }

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Files synced successfully ✓\033[0m"
@endtask

@task('prepare_app_dir', ['on' => 'web_new'])
    set -e
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Preparing application directory...\033[0m"

    sudo rm -rf {{ $remote_html_path }}.temp 2>/dev/null || true
    sudo cp -r {{ $tempDir }} {{ $remote_html_path }}.temp || { echo "Error: Failed to copy directory {{ $tempDir }} to {{ $remote_html_path }}.temp"; exit 1; }

    test -f {{ $remote_html_path }}.temp/docker-compose.yml || { echo "Error: docker-compose.yml not found after copy"; exit 1; }
    test -f {{ $remote_html_path }}.temp/scripts/deploy/compose-stack.sh || { echo "Error: scripts/deploy/compose-stack.sh not found after copy"; exit 1; }
    test -f {{ $remote_html_path }}.temp/docker/configure.sh || { echo "Error: docker/configure.sh not found after copy"; exit 1; }
    test -f {{ $remote_html_path }}.temp/docker/start-queue.sh || { echo "Error: docker/start-queue.sh not found after copy"; exit 1; }
    test -f {{ $remote_html_path }}.temp/.env || { echo "Error: .env not found after copy"; exit 1; }

    sudo rm -rf {{ $remote_html_path }}.backup 2>/dev/null || true
    sudo mv {{ $remote_html_path }} {{ $remote_html_path }}.backup 2>/dev/null || true
    sudo mv {{ $remote_html_path }}.temp {{ $remote_html_path }} || { echo "Error: Failed to move {{ $remote_html_path }}.temp to {{ $remote_html_path }}"; exit 1; }

    sudo chown -R deploy:deploy {{ $remote_html_path }} || { echo "Error: Failed to set ownership for {{ $remote_html_path }}"; exit 1; }

    tempDirReal=$(realpath "{{ $tempDir }}")
    if [[ "$tempDirReal" == /tmp/* && "$tempDirReal" != "/tmp" && "$tempDirReal" != "/tmp/" ]]; then
        echo "Deleting $tempDirReal"
        rm -r "$tempDirReal" || { echo "Error: Failed to delete $tempDirReal"; exit 1; }
    else
        echo "Refusing to delete potentially unsafe path: $tempDirReal"
    fi

    mkdir -p {{ $remote_html_path }}/storage/logs || { echo "Error: Failed to create storage/logs"; exit 1; }
    mkdir -p {{ $remote_html_path }}/storage/framework/cache || { echo "Error: Failed to create storage/framework/cache"; exit 1; }
    mkdir -p {{ $remote_html_path }}/storage/framework/sessions || { echo "Error: Failed to create storage/framework/sessions"; exit 1; }
    mkdir -p {{ $remote_html_path }}/storage/framework/views || { echo "Error: Failed to create storage/framework/views"; exit 1; }

    chmod +x {{ $remote_html_path }}/docker/configure.sh || { echo "Error: Failed to make configure.sh executable"; exit 1; }
    chmod +x {{ $remote_html_path }}/docker/start-queue.sh || { echo "Error: Failed to make start-queue.sh executable"; exit 1; }
    chmod +x {{ $remote_html_path }}/scripts/deploy/compose-stack.sh || { echo "Error: Failed to make compose-stack.sh executable"; exit 1; }

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Application directory prepared ✓\033[0m"
@endtask

@task('remove_project_stack', ['on' => 'web_new'])
    set -euo pipefail

    SITES_ROOT="{{ $sites_root }}"
    PROJECT_SLUG="{{ $remove_project_slug }}"
    CONFIRMED="{{ $remove_project_confirmed }}"

    if [ "$CONFIRMED" != "true" ]; then
        echo "[ERROR] Removal is not confirmed. Re-run 'php vendor/bin/envoy run remove_project'"
        exit 1
    fi

    PROJECT_DIR="$SITES_ROOT/$PROJECT_SLUG"
    REMOVED_DIR="$PROJECT_DIR.removed.$(date +%Y%m%d_%H%M%S)"
    BACKEND_SLUG=$(echo "$PROJECT_SLUG" | tr -cd '[:alnum:]' | tr '[:upper:]' '[:lower:]')
    BACKEND_NAME="traefik-$BACKEND_SLUG"

    if [ ! -d "$PROJECT_DIR" ]; then
        echo -e "\033[0;31m[$(date +'%Y-%m-%d %H:%M:%S')] Project directory not found: $PROJECT_DIR\033[0m"
        exit 1
    fi

    if [ -f "$PROJECT_DIR/docker-compose.yml" ]; then
        echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Stopping project containers...\033[0m"
        cd "$PROJECT_DIR"
        docker compose down --remove-orphans || true
    fi

    cd /
    sudo mv "$PROJECT_DIR" "$REMOVED_DIR"
    sudo chown -R deploy:deploy "$REMOVED_DIR" || true

    # After removing the project directory, also remove stale HAProxy map entries
    # so the reverse-proxy doesn't preserve mappings for this deleted site.
    echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Cleaning HAProxy config maps for removed project...\033[0m"

    CONFIG_VOL=""
    REVERSE_DIR="{{ $sites_root }}/{{ $reverse_proxy_folder_name }}"

    # Try to deterministically resolve the haproxy-config volume from the reverse-proxy compose
    if [ -d "$REVERSE_DIR" ] && [ -f "$REVERSE_DIR/docker-compose.yml" ]; then
        pushd "$REVERSE_DIR" >/dev/null 2>&1 || true
        if docker compose config --format json >/dev/null 2>&1; then
            CONFIG_VOL=$(docker compose config --format json | jq -r '.volumes["haproxy-config"].name // empty' 2>/dev/null || echo "")
            if [ -z "$CONFIG_VOL" ] || [ "$CONFIG_VOL" = "null" ]; then
                is_external=$(docker compose config --format json | jq -r '.volumes["haproxy-config"].external // false' 2>/dev/null || echo "false")
                if [ "$is_external" = "true" ]; then
                    CONFIG_VOL=$(docker compose config --format json | jq -r '.volumes["haproxy-config"].name // "haproxy-config"' 2>/dev/null || echo "haproxy-config")
                else
                    project_name=$(docker compose config --project-name 2>/dev/null || echo "")
                    custom_name=$(docker compose config --format json | jq -r '.volumes["haproxy-config"].name // empty' 2>/dev/null || echo "")
                    if [ -n "$custom_name" ] && [ "$custom_name" != "null" ]; then
                        CONFIG_VOL="$custom_name"
                    else
                        CONFIG_VOL="${project_name}_haproxy-config"
                    fi
                fi
            fi
        fi
        popd >/dev/null 2>&1 || true
    fi

    # Fallback: scan volumes for any containing /data/traefik_backends.map
    if [ -z "$CONFIG_VOL" ]; then
        for v in $(docker volume ls -q); do
            if docker run --rm -v "${v}":/data:ro alpine sh -c 'test -f /data/traefik_backends.map && echo yes' 2>/dev/null | grep -q yes; then
                CONFIG_VOL="$v"
                break
            fi
        done
    fi

    if [ -n "$CONFIG_VOL" ]; then
        echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Found HAProxy config volume: $CONFIG_VOL\033[0m"

        docker run --rm -e BACKEND="$BACKEND_NAME" -v "$CONFIG_VOL":/data alpine sh -c '
            # Preserve comments/blank lines; drop entries where 2nd field equals backend
            awk -v be="$BACKEND" '\''NF<2 || $2 != be {print}'\'' /data/traefik_backends.map > /data/traefik_backends.map.tmp || true
            mv /data/traefik_backends.map.tmp /data/traefik_backends.map || true

            # Remove any per-site map files that still reference this backend
            for f in /data/maps/*.map; do
                [ -f "$f" ] || continue
                if grep -q "$BACKEND" "$f"; then
                    rm -f "$f"
                fi
            done

            echo "Updated /data/traefik_backends.map (tail):"
            tail -n 50 /data/traefik_backends.map || true
        ' || true
    else
        echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] No HAProxy config volume found, skipping map cleanup\033[0m"
    fi

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Project '$PROJECT_SLUG' moved out of active sites: $REMOVED_DIR\033[0m"
@endtask

@task('deploy_haproxy_compose', ['on' => 'local'])
    set -e
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Deploying HAProxy docker-compose...\033[0m"

    test -f deploy/docker-compose/docker-compose.haproxy.prod.yml || { echo "Error: deploy/docker-compose/docker-compose.haproxy.prod.yml not found locally"; exit 1; }
    test -d deploy/docker-compose/docker/haproxy || { echo "Error: deploy/docker-compose/docker/haproxy directory not found locally"; exit 1; }

    # Create reverse-proxy directory structure on remote server
    ssh -i {{ env('SSH_KEY_PATH') }} -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no {{ 'deploy@' . env('SERVER_IP') }} "sudo mkdir -p /var/www/reverse-proxy/docker && sudo chown -R deploy:deploy /var/www/reverse-proxy" || { echo "Error: Failed to create /var/www/reverse-proxy on remote server"; exit 1; }

    # Copy docker-compose file
    scp -i {{ env('SSH_KEY_PATH') }} -P {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no deploy/docker-compose/docker-compose.haproxy.prod.yml {{ 'deploy@' . env('SERVER_IP') }}:/var/www/reverse-proxy/docker-compose.yml || { echo "Error: Failed to copy docker-compose.haproxy.prod.yml"; exit 1; }

    # Copy haproxy docker directory
    scp -r -i {{ env('SSH_KEY_PATH') }} -P {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no deploy/docker-compose/docker/haproxy {{ 'deploy@' . env('SERVER_IP') }}:/var/www/reverse-proxy/docker/ || { echo "Error: Failed to copy docker/haproxy directory"; exit 1; }

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] HAProxy docker-compose deployed successfully ✓\033[0m"
@endtask

@task('deploy_haproxy_config', ['on' => 'local'])
    set -e
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Configuring HAProxy...\033[0m"

    # Check local files exist
    test -f deploy/configs/haproxy.cfg || { echo "Error: deploy/configs/haproxy.cfg not found locally"; exit 1; }
    test -f deploy/configs/blocked_ips.txt || { echo "Error: deploy/configs/blocked_ips.txt not found locally"; exit 1; }

    # Create remote directory as root, then change owner to deploy
    ssh -i {{ env('SSH_KEY_PATH') }} -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no {{ 'deploy@' . env('SERVER_IP') }} "
        sudo mkdir -p {{ $sites_root }}/{{ $reverse_proxy_folder_name }}/haproxy &&
        sudo chown -R deploy:deploy {{ $sites_root }}/{{ $reverse_proxy_folder_name }}/haproxy
    " || { echo "Error: Failed to create {{ $sites_root }}/{{ $reverse_proxy_folder_name }}/haproxy on remote server"; exit 1; }

    # Copy config files
    scp -i {{ env('SSH_KEY_PATH') }} -P {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no deploy/configs/haproxy.cfg {{ 'deploy@' . env('SERVER_IP') }}:{{ $sites_root }}/{{ $reverse_proxy_folder_name }}/haproxy/haproxy.cfg || { echo "Error: Failed to copy haproxy.cfg"; exit 1; }
    scp -i {{ env('SSH_KEY_PATH') }} -P {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no deploy/configs/blocked_ips.txt {{ 'deploy@' . env('SERVER_IP') }}:{{ $sites_root }}/{{ $reverse_proxy_folder_name }}/haproxy/blocked_ips.txt || { echo "Error: Failed to copy blocked_ips.txt"; exit 1; }

    # Ensure ownership and permissions
    ssh -i {{ env('SSH_KEY_PATH') }} -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no {{ 'deploy@' . env('SERVER_IP') }} "
        sudo chown -R deploy:deploy {{ $sites_root }}/{{ $reverse_proxy_folder_name }}/haproxy &&
        sudo chmod 644 {{ $sites_root }}/{{ $reverse_proxy_folder_name }}/haproxy/haproxy.cfg &&
        sudo chmod 644 {{ $sites_root }}/{{ $reverse_proxy_folder_name }}/haproxy/blocked_ips.txt
    " || { echo "Error: Failed to set ownership or permissions"; exit 1; }

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] HAProxy configuration updated successfully ✓\033[0m"
@endtask

@task('cert-manager-start', ['on' => 'web_new'])
    get_real_compose_volume() {
        local logical_name="$1"

        # Get config in JSON; fail fast if YAML is broken
        local full_config
        if ! full_config=$(docker compose config --format json); then
            echo "Error: Failed to parse docker-compose config." >&2
            return 1
        fi

        # Extract volume data and check if logical name exists
        local volume_data
        volume_data=$(echo "$full_config" | jq -e ".volumes.\"$logical_name\"") || {
            echo "Error: Volume '$logical_name' not defined in compose file." >&2
            return 1
        }

        local is_external=$(echo "$volume_data" | jq -r '.external // false')
        local volume_name=""

        if [ "$is_external" = "true" ]; then
            # For external: use explicit name OR the logical key itself
            volume_name=$(echo "$volume_data" | jq -r '.name // "'"$logical_name"'"')
        else
            # For local: use project prefix
            local project_name=$(docker compose config --project-name)
            local custom_name=$(echo "$volume_data" | jq -r '.name // empty')

            if [ -n "$custom_name" ] && [ "$custom_name" != "null" ]; then
                volume_name="$custom_name"
            else
                volume_name="${project_name}_${logical_name}"
            fi
        fi

        # Final check: does it actually exist in Docker?
        if ! docker volume inspect "$volume_name" >/dev/null 2>&1; then
            echo "Error: Docker volume '$volume_name' not found." >&2
            return 1
        fi

        echo "$volume_name"
    }

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Starting cert-manager...\033[0m"

    cd {{ $remote_html_path }} || { echo -e "\033[0;31m[$(date +'%Y-%m-%d %H:%M:%S')] Failed to cd to {{ $remote_html_path }}\033[0m"; exit 1; }

    VOLUME_NAME=$(get_real_compose_volume "haproxy-certs")

    if ! docker volume inspect "$VOLUME_NAME" >/dev/null 2>&1; then
        echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Creating certificates volume ($VOLUME_NAME)...\033[0m"
        docker volume create "$VOLUME_NAME" || {
            echo -e "\033[0;31m[$(date +'%Y-%m-%d %H:%M:%S')] Failed to create $VOLUME_NAME volume\033[0m";
            exit 1;
        }
    else
        echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] $VOLUME_NAME volume already exists\033[0m"
    fi

    if docker compose config --services | grep -q "cert-manager"; then
        echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Building and starting cert-manager service...\033[0m"
        if docker compose up -d --build cert-manager; then
            echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Waiting for cert-manager to be healthy...\033[0m"
            until docker inspect --format='@{{.State.Health.Status}}' $(docker compose ps -q cert-manager) | grep -q "healthy"; do
                echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Still waiting...\033[0m"
                sleep 1
            done
            echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] cert-manager started successfully and is healthy\033[0m"
        else
            echo -e "\033[0;31m[$(date +'%Y-%m-%d %H:%M:%S')] Failed to start cert-manager\033[0m"
            exit 1
        fi
    else
        echo -e "\033[0;31m[$(date +'%Y-%m-%d %H:%M:%S')] cert-manager service not found in docker-compose.yml\033[0m"
        exit 1
    fi
@endtask

@task('update_haproxy_volumes', ['on' => 'web_new'])
    get_real_compose_volume() {
        local logical_name="$1"

        # 1. Validate YAML and get config
        local full_config
        if ! full_config=$(docker compose config --format json); then
            echo "Error: Failed to parse docker-compose config." >&2
            return 1
        fi

        # 2. Extract volume data
        local volume_data
        volume_data=$(echo "$full_config" | jq -e ".volumes.\"$logical_name\"") || {
            echo "Error: Volume '$logical_name' not defined." >&2
            return 1
        }

        # 3. Determine actual volume name
        local is_external=$(echo "$volume_data" | jq -r '.external // false')
        local volume_name=""

        if [ "$is_external" = "true" ]; then
            # Use .name if exists, otherwise fallback to logical key
            volume_name=$(echo "$volume_data" | jq -r '.name // "'"$logical_name"'"')
        else
            # Handle project-scoped volumes
            local project_name=$(docker compose config --project-name)
            local custom_name=$(echo "$volume_data" | jq -r '.name // empty')

            if [ -n "$custom_name" ] && [ "$custom_name" != "null" ]; then
                volume_name="$custom_name"
            else
                volume_name="${project_name}_${logical_name}"
            fi
        fi

        # 4. Final existence check in Docker
        if ! docker volume inspect "$volume_name" >/dev/null 2>&1; then
            echo "Error: Docker volume '$volume_name' does not exist." >&2
            return 1
        fi

        echo "$volume_name"
    }

    set -e

    SITES_ROOT={{ $sites_root }}
    REVERSE_PROXY_DIR="$SITES_ROOT/{{ $reverse_proxy_folder_name }}"

    if [ ! -d "$REVERSE_PROXY_DIR" ] || [ ! -f "$REVERSE_PROXY_DIR/docker-compose.yml" ]; then
        echo "Error: reverse-proxy directory is not ready: $REVERSE_PROXY_DIR"
        exit 1
    fi

    cd "$REVERSE_PROXY_DIR"
    HAPROXY_WORKING_DIR="$REVERSE_PROXY_DIR"
    TIMESTAMP="$(date +'%Y-%m-%d %H:%M:%S')"

    echo "[$TIMESTAMP] Starting HAProxy deployment for $HOST -> $BACKEND_NAME"
    echo "[$TIMESTAMP] Mode: Multi-site"

    cd "$HAPROXY_WORKING_DIR"

    rm -f /tmp/haproxy_deploy_flag

    TIMESTAMP="$(date +'%Y-%m-%d %H:%M:%S')"
    echo -e "\033[0;32m[$TIMESTAMP] Starting HAProxy volume update with change detection...\033[0m"

    VOLUME_NAME=$(get_real_compose_volume "haproxy-config")

    if [ ! -f "$HAPROXY_WORKING_DIR/haproxy/haproxy.cfg" ] || [ ! -f "$HAPROXY_WORKING_DIR/haproxy/blocked_ips.txt" ]; then
        echo -e "\033[0;31m[$TIMESTAMP] Error: Configuration files not found in {{ $remote_html_path }}/haproxy/\033[0m"
        echo "HAPROXY_DEPLOY_NEEDED=error" > /tmp/haproxy_deploy_flag
        exit 1
    fi

    echo -e "\033[0;33m[$TIMESTAMP] Checking for configuration changes...\033[0m"

    LOCAL_CONFIG_HASH=$(md5sum $HAPROXY_WORKING_DIR/haproxy/haproxy.cfg | cut -d' ' -f1)
    LOCAL_IPS_HASH=$(md5sum $HAPROXY_WORKING_DIR/haproxy/blocked_ips.txt | cut -d' ' -f1)

    echo "Local file hashes:"
    echo "  haproxy.cfg: $LOCAL_CONFIG_HASH"
    echo "  blocked_ips.txt: $LOCAL_IPS_HASH"

    if ! docker volume inspect "$VOLUME_NAME" >/dev/null 2>&1; then
        echo -e "\033[0;33m[$TIMESTAMP] Creating configuration volume ($VOLUME_NAME)...\033[0m"
        docker volume create "$VOLUME_NAME" || {
            echo -e "\033[0;31m[$TIMESTAMP] Failed to create $VOLUME_NAME volume\033[0m"
            echo "HAPROXY_DEPLOY_NEEDED=error" > /tmp/haproxy_deploy_flag
            exit 1
        }
        echo -e "\033[0;32m[$TIMESTAMP] Volume created, deployment will be needed\033[0m"
        echo "HAPROXY_DEPLOY_NEEDED=true" > /tmp/haproxy_deploy_flag
    else
        echo -e "\033[0;32m[$TIMESTAMP] $VOLUME_NAME volume already exists\033[0m"

        VOLUME_CONFIG_HASH=$(docker run --rm -v "$VOLUME_NAME":/data alpine sh -c "md5sum /data/haproxy.cfg 2>/dev/null | cut -d' ' -f1" || echo "")
        VOLUME_IPS_HASH=$(docker run --rm -v "$VOLUME_NAME":/data alpine sh -c "md5sum /data/blocked_ips.txt 2>/dev/null | cut -d' ' -f1" || echo "")

        echo "Volume file hashes:"
        echo "  haproxy.cfg: ${VOLUME_CONFIG_HASH:-'<missing>'}"
        echo "  blocked_ips.txt: ${VOLUME_IPS_HASH:-'<missing>'}"

        if [ -n "$VOLUME_CONFIG_HASH" ] && [ -n "$VOLUME_IPS_HASH" ] &&
           [ "$LOCAL_CONFIG_HASH" = "$VOLUME_CONFIG_HASH" ] && [ "$LOCAL_IPS_HASH" = "$VOLUME_IPS_HASH" ]; then
            echo -e "\033[0;32m[$TIMESTAMP] No configuration changes detected\033[0m"
            echo "HAPROXY_DEPLOY_NEEDED=false" > /tmp/haproxy_deploy_flag
        else
            echo -e "\033[0;33m[$TIMESTAMP] Configuration changes detected:\033[0m"
            [ "$LOCAL_CONFIG_HASH" != "$VOLUME_CONFIG_HASH" ] && echo "  - haproxy.cfg changed (or missing in volume)"
            [ "$LOCAL_IPS_HASH" != "$VOLUME_IPS_HASH" ] && echo "  - blocked_ips.txt changed (or missing in volume)"
            echo -e "\033[0;33m[$TIMESTAMP] Deployment will be needed\033[0m"
            echo "HAPROXY_DEPLOY_NEEDED=true" > /tmp/haproxy_deploy_flag
        fi
    fi

    echo -e "\033[0;33m[$TIMESTAMP] Updating configuration files in volume...\033[0m"
    cd $HAPROXY_WORKING_DIR/haproxy/

    docker run --rm \
        -v "$VOLUME_NAME":/data \
        -v "$(pwd)":/src \
        alpine cp /src/haproxy.cfg /data/haproxy.cfg || {
            echo -e "\033[0;31m[$TIMESTAMP] Failed to update haproxy.cfg\033[0m"
            echo "HAPROXY_DEPLOY_NEEDED=error" > /tmp/haproxy_deploy_flag
            exit 1
        }
    echo "✅ haproxy.cfg updated"

    docker run --rm \
        -v "$VOLUME_NAME":/data \
        -v "$(pwd)":/src \
        alpine cp /src/blocked_ips.txt /data/blocked_ips.txt || {
            echo -e "\033[0;31m[$TIMESTAMP] Failed to update blocked_ips.txt\033[0m"
            echo "HAPROXY_DEPLOY_NEEDED=error" > /tmp/haproxy_deploy_flag
            exit 1
        }
    echo "✅ blocked_ips.txt updated"

    NEW_CONFIG_HASH=$(docker run --rm -v "$VOLUME_NAME":/data alpine sh -c "md5sum /data/haproxy.cfg | cut -d' ' -f1")
    NEW_IPS_HASH=$(docker run --rm -v "$VOLUME_NAME":/data alpine sh -c "md5sum /data/blocked_ips.txt | cut -d' ' -f1")

    if [ "$LOCAL_CONFIG_HASH" = "$NEW_CONFIG_HASH" ] && [ "$LOCAL_IPS_HASH" = "$NEW_IPS_HASH" ]; then
        echo "✅ Volume files verified successfully"
    else
        echo -e "\033[0;31m[$TIMESTAMP] Error: Volume file verification failed\033[0m"
        echo "HAPROXY_DEPLOY_NEEDED=error" > /tmp/haproxy_deploy_flag
        exit 1
    fi

    DEPLOY_STATUS=$(cat /tmp/haproxy_deploy_flag | cut -d'=' -f2)
    case $DEPLOY_STATUS in
        "true")
            echo -e "\033[0;33m[$TIMESTAMP] Volume updated. HAProxy deployment will be required.\033[0m"
            ;;
        "false")
            echo -e "\033[0;32m[$TIMESTAMP] Volume updated. No HAProxy deployment needed.\033[0m"
            ;;
        "error")
            echo -e "\033[0;31m[$TIMESTAMP] Volume update completed with errors.\033[0m"
            ;;
    esac

    echo -e "\033[0;32m[$TIMESTAMP] HAProxy volume update completed ✓\033[0m"
@endtask

@task('prepare_secrets_dir', ['on' => 'web_new'])
    echo -e "\033[0;32m[INFO] Ensuring /var/secrets/{{ $site_name }} directory exists on remote server...\033[0m"

    sudo mkdir -p /var/secrets/{{ $site_name }}
    sudo chown deploy:deploy /var/secrets/{{ $site_name }}
    sudo chmod 700 /var/secrets/{{ $site_name }}

    echo -e "\033[0;32m[SUCCESS] /var/secrets/{{ $site_name }} directory ready\033[0m"
@endtask

@task('backup_server_secrets', ['on' => 'web_new'])
    echo -e "\033[0;32m[INFO] Backing up current server secrets...\033[0m"

    SECRET_DIR="/var/secrets/{{ $site_name }}"
    SECRET_BACKUP_DIR="$SECRET_DIR/.rollback-backup"

    sudo mkdir -p "$SECRET_BACKUP_DIR"
    sudo chmod 700 "$SECRET_BACKUP_DIR"
    sudo chown deploy:deploy "$SECRET_BACKUP_DIR"

    backup_secret_file() {
        local secret_basename="$1"
        local source_file="$SECRET_DIR/${secret_basename}.txt"
        local backup_file="$SECRET_BACKUP_DIR/${secret_basename}.txt"
        local missing_marker="$SECRET_BACKUP_DIR/${secret_basename}.missing"

        sudo rm -f "$backup_file" "$missing_marker"

        if [ -f "$source_file" ]; then
            sudo cp "$source_file" "$backup_file"
            sudo chmod 600 "$backup_file"
            sudo chown deploy:deploy "$backup_file"
            echo -e "\033[0;34m[INFO] Backed up $secret_basename secret\033[0m"
        else
            sudo touch "$missing_marker"
            sudo chmod 600 "$missing_marker"
            sudo chown deploy:deploy "$missing_marker"
            echo -e "\033[0;34m[INFO] Secret $secret_basename did not exist before deploy\033[0m"
        fi
    }

    backup_secret_file app_key
    backup_secret_file db_password
    backup_secret_file cf

    echo -e "\033[0;32m[SUCCESS] Secret backup completed\033[0m"
@endtask

@task('generate_server_secrets', ['on' => 'web_new'])
    echo -e "\033[0;32m[INFO] Starting secrets generation...\033[0m"

    cd {{ $remote_html_path }} || { echo -e "\033[0;31m[ERROR] Failed to cd to {{ $remote_html_path }}\033[0m"; exit 1; }

    if [ ! -f docker-compose.yml ]; then
        echo -e "\033[0;31m[ERROR] docker-compose.yml not found. Run generate_production_compose first.\033[0m"
        exit 1
    fi

    mapfile -t REQUIRED_SECRETS < <(bash scripts/deploy/compose-stack.sh list-required-secrets docker-compose.yml)

    requires_secret() {
        local secret_name="$1"
        printf '%s\n' "${REQUIRED_SECRETS[@]:-}" | grep -qx "$secret_name"
    }

    restore_secret_from_running_containers() {
        local secret_name="$1"
        local secret_file="$2"
        local secret_mount_path="$3"
        shift 3

        local pattern
        local container_name
        local secret_value

        for pattern in "$@"; do
            container_name=$(docker ps --format "@{{.Names}}" | grep -E "$pattern" | head -n 1 || true)

            if [ -z "$container_name" ]; then
                continue
            fi

            secret_value=$(docker exec "$container_name" sh -lc "if [ -f '$secret_mount_path' ]; then cat '$secret_mount_path'; fi" 2>/dev/null || true)

            if [ -n "$secret_value" ]; then
                printf '%s' "$secret_value" | sudo tee "$secret_file" > /dev/null
                sudo chmod 600 "$secret_file"
                sudo chown deploy:deploy "$secret_file"
                echo -e "\033[0;34m[INFO] Restored $secret_name from running container $container_name\033[0m"
                return 0
            fi
        done

        return 1
    }

    APP_KEY_FILE="/var/secrets/{{ $site_name }}/app_key.txt"
    DB_PASSWORD_FILE="/var/secrets/{{ $site_name }}/db_password.txt"
    CF_FILE="/var/secrets/{{ $site_name }}/cf.txt"

    app_key_is_valid() {
    local key_file="$1"
    [ -s "$key_file" ] || return 1

    local key_value
    key_value=$(tr -d '\r\n' < "$key_file")

    case "$key_value" in
        base64:*) ;;
        *) return 1 ;;
    esac

    local encoded_value="${key_value#base64:}"
    local decoded_len
    decoded_len=$(printf '%s' "$encoded_value" | base64 -d 2>/dev/null | wc -c | tr -d ' ')

    [ "$decoded_len" = "32" ]
    }

    generate_app_key_secret() {
    local raw_key=""
    local chunk

    while [ ${#raw_key} -lt 32 ]; do
        chunk=$(openssl rand 32 | base64 | tr -dc 'A-Za-z0-9')
        raw_key="${raw_key}${chunk}"
    done

    raw_key=${raw_key:0:32}
    local app_key="base64:$(echo -n "$raw_key" | base64)"

    echo "$app_key" | sudo tee "$APP_KEY_FILE" > /dev/null
    sudo chmod 600 "$APP_KEY_FILE"
    sudo chown deploy:deploy "$APP_KEY_FILE"

    echo -e "\033[0;34m[INFO] Generated new APP_KEY\033[0m"
    }

    if requires_secret app_key; then
    if ! app_key_is_valid "$APP_KEY_FILE"; then
    if restore_secret_from_running_containers app_key "$APP_KEY_FILE" /run/secrets/app_key '^{{ $container_prefix }}app-(blue|green)$' '^{{ $container_prefix }}queue-(blue|green)$' && app_key_is_valid "$APP_KEY_FILE"; then
    echo -e "\033[0;34m[INFO] Recovered existing APP_KEY, skipping regeneration\033[0m"
    else
    if [ -f "$APP_KEY_FILE" ]; then
    echo -e "\033[0;33m[WARN] Existing APP_KEY is missing or invalid, generating a new one\033[0m"
    fi
    generate_app_key_secret
    fi
    else
    echo -e "\033[0;34m[INFO] APP_KEY already exists and looks valid, skipping\033[0m"
    fi
    else
    echo -e "\033[0;34m[INFO] APP_KEY not required by current compose, skipping\033[0m"
    fi

    if requires_secret db_password; then
    if [ ! -f "$DB_PASSWORD_FILE" ]; then
    if restore_secret_from_running_containers db_password "$DB_PASSWORD_FILE" /run/secrets/db_password '^{{ $container_prefix }}postgres(-[0-9]+)?$' '^{{ $container_prefix }}app-(blue|green)$' '^{{ $container_prefix }}queue-(blue|green)$'; then
    echo -e "\033[0;34m[INFO] Recovered existing DB_PASSWORD, skipping regeneration\033[0m"
    else
    password=""
    while [ ${#password} -lt 16 ]; do
        chunk=$(openssl rand 32 | base64 | tr -dc 'A-Za-z0-9')
        password="${password}${chunk}"
    done
    password=${password:0:16}

    echo "$password" | sudo tee "$DB_PASSWORD_FILE" > /dev/null
    sudo chmod 600 "$DB_PASSWORD_FILE"
    sudo chown deploy:deploy "$DB_PASSWORD_FILE"

    echo -e "\033[0;34m[INFO] Generated new DB_PASSWORD\033[0m"
    fi
    else
    echo -e "\033[0;34m[INFO] DB_PASSWORD already exists, skipping\033[0m"
    fi
    else
    echo -e "\033[0;34m[INFO] DB_PASSWORD not required by current compose, skipping\033[0m"
    fi


    echo -e "\033[0;32m[SUCCESS] Secrets generation completed!\033[0m"
@endtask

@task('cleanup_server_secrets', ['on' => 'web_new'])
    echo -e "\033[0;32m[INFO] Starting manual secrets cleanup...\033[0m"

    cd {{ $remote_html_path }} || { echo -e "\033[0;31m[ERROR] Failed to cd to {{ $remote_html_path }}\033[0m"; exit 1; }

    if [ ! -f docker-compose.yml ]; then
        echo -e "\033[0;31m[ERROR] docker-compose.yml not found. Run generate_production_compose first.\033[0m"
        exit 1
    fi

    mapfile -t REQUIRED_SECRETS < <(bash scripts/deploy/compose-stack.sh list-required-secrets docker-compose.yml)

    requires_secret() {
        local secret_name="$1"
        printf '%s\n' "${REQUIRED_SECRETS[@]:-}" | grep -qx "$secret_name"
    }

    secret_used_by_project_container() {
        local secret_mount_path="$1"
        local container_id

        while IFS= read -r container_id; do
            [ -n "$container_id" ] || continue

            if docker inspect "$container_id" 2>/dev/null | jq -e --arg destination "$secret_mount_path" '.[0].Mounts[]? | select(.Destination == $destination)' >/dev/null; then
                docker inspect --format '@{{.Name}}' "$container_id" 2>/dev/null | sed 's#^/##'
                return 0
            fi
        done < <(docker ps -aq --filter "name=^{{ $container_prefix }}")

        return 1
    }

    cleanup_secret_file() {
        local secret_name="$1"
        local secret_file="$2"
        local secret_mount_path="$3"

        if requires_secret "$secret_name"; then
            echo -e "\033[0;34m[INFO] Secret $secret_name is still required by current compose, keeping file\033[0m"
            return 0
        fi

        if [ ! -f "$secret_file" ]; then
            echo -e "\033[0;34m[INFO] Secret file for $secret_name does not exist, nothing to remove\033[0m"
            return 0
        fi

        using_container=$(secret_used_by_project_container "$secret_mount_path" || true)
        if [ -n "$using_container" ]; then
            echo -e "\033[0;33m[WARNING] Secret $secret_name is still mounted in container $using_container, skipping removal\033[0m"
            return 0
        fi

        sudo rm -f "$secret_file"
        echo -e "\033[0;32m[SUCCESS] Removed unused secret file for $secret_name\033[0m"
    }

    cleanup_secret_file app_key "/var/secrets/{{ $site_name }}/app_key.txt" "/run/secrets/app_key"
    cleanup_secret_file db_password "/var/secrets/{{ $site_name }}/db_password.txt" "/run/secrets/db_password"
    cleanup_secret_file cf "/var/secrets/{{ $site_name }}/cf.txt" "/run/secrets/cf"

    echo -e "\033[0;32m[SUCCESS] Manual secrets cleanup completed\033[0m"
@endtask

@task('restore_server_secrets', ['on' => 'web_new'])
    echo -e "\033[0;32m[INFO] Restoring server secrets from rollback backup...\033[0m"

    SECRET_DIR="/var/secrets/{{ $site_name }}"
    SECRET_BACKUP_DIR="$SECRET_DIR/.rollback-backup"

    if [ ! -d "$SECRET_BACKUP_DIR" ]; then
        echo -e "\033[0;34m[INFO] No secret backup directory found, skipping restore\033[0m"
        exit 0
    fi

    restore_secret_file() {
        local secret_basename="$1"
        local source_file="$SECRET_DIR/${secret_basename}.txt"
        local backup_file="$SECRET_BACKUP_DIR/${secret_basename}.txt"
        local missing_marker="$SECRET_BACKUP_DIR/${secret_basename}.missing"

        if [ -f "$backup_file" ]; then
            sudo cp "$backup_file" "$source_file"
            sudo chmod 600 "$source_file"
            sudo chown deploy:deploy "$source_file"
            echo -e "\033[0;34m[INFO] Restored $secret_basename from backup\033[0m"
            return 0
        fi

        if [ -f "$missing_marker" ]; then
            sudo rm -f "$source_file"
            echo -e "\033[0;34m[INFO] Removed newly created $secret_basename secret during rollback\033[0m"
            return 0
        fi

        echo -e "\033[0;34m[INFO] No backup state found for $secret_basename, leaving as is\033[0m"
    }

    restore_secret_file app_key
    restore_secret_file db_password
    restore_secret_file cf

    echo -e "\033[0;32m[SUCCESS] Secret restore completed\033[0m"
@endtask

@task('cleanup_secret_backups', ['on' => 'web_new'])
    echo -e "\033[0;32m[INFO] Cleaning up secret rollback backups...\033[0m"

    SECRET_BACKUP_DIR="/var/secrets/{{ $site_name }}/.rollback-backup"

    if [ -d "$SECRET_BACKUP_DIR" ]; then
        sudo rm -rf "$SECRET_BACKUP_DIR"
        echo -e "\033[0;32m[SUCCESS] Secret rollback backups removed\033[0m"
    else
        echo -e "\033[0;34m[INFO] No secret rollback backups found, nothing to clean\033[0m"
    fi
@endtask

@task('generate_production_compose', ['on' => 'web_new'])
#!/bin/bash
set -euo pipefail

log() {
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] $1\033[0m"
}

error() {
    echo -e "\033[0;31m[$(date +'%Y-%m-%d %H:%M:%S')] ERROR: $1\033[0m" >&2
}

load_env_file() {
    local env_file="${1:-.env}"

    if [ ! -f "$env_file" ]; then
        error "Env file '$env_file' not found"
        return 1
    fi

    # Line by line without variable substitution
    while IFS='=' read -r key value || [ -n "$key" ]; do
        # Skip comments and empty lines
        [[ "$key" =~ ^[[:space:]]*# ]] && continue
        [[ -z "$key" ]] && continue

        # Remove whitespace around key
        key=$(echo "$key" | xargs echo -n)

        # Remove surrounding quotes from value (if present)
        value=$(echo "$value" | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//")

        # Export WITHOUT eval (no substitution)
        export "$key=$value"
    done < "$env_file"

    log "Environment variables loaded from $env_file"
    return 0
}

detect_current_color() {
    if docker ps --format "@{{.Names}}" | grep -q "^{{ $container_prefix }}app-blue"; then
        echo "blue"
    elif docker ps --format "@{{.Names}}" | grep -q "^{{ $container_prefix }}app-green"; then
        echo "green"
    else
        echo ""
    fi
}

get_opposite_color() {
    local current_color="$1"

    if [ "$current_color" = "blue" ]; then
        echo "green"
    elif [ "$current_color" = "green" ]; then
        echo "blue"
    else
        # First deployment - default to blue
        echo "blue"
    fi
}

upsert_env_var() {
    local env_file="$1"
    local key="$2"
    local value="$3"

    if grep -q "^${key}=" "$env_file"; then
        sed -i "s/^${key}=.*/${key}=${value}/" "$env_file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$env_file"
    fi
}

log "=========================================="
log "STARTING PRODUCTION COMPOSE GENERATION"
log "=========================================="

cd {{ $remote_html_path }} || {
    error "Failed to change directory to {{ $remote_html_path }}"
    exit 1
}
log "Changed directory to {{ $remote_html_path }} ✓"

log "Loading environment variables..."
load_env_file ".env"
log "Environment loaded ✓"

log "Detecting current deployment color..."
CURRENT_COLOR=$(detect_current_color)
NEW_COLOR=$(get_opposite_color "$CURRENT_COLOR")

log "Current deployment: ${CURRENT_COLOR:-none}"
log "New deployment will be: $NEW_COLOR"

export DEPLOYMENT_COLOR="$NEW_COLOR"
export DEPLOYMENT_TIMESTAMP="$(date +%s)"
export SITE_PREFIX="{{ $prefix }}"

export HEALTHCHECK_INTERVAL="${DEPLOYMENT_HEALTHCHECK_INTERVAL:-2s}"
export HEALTHCHECK_TIMEOUT="${DEPLOYMENT_HEALTHCHECK_TIMEOUT:-1s}"
export HEALTHCHECK_RETRIES="${DEPLOYMENT_HEALTHCHECK_RETRIES:-2}"

export APP_MEMORY_LIMIT="${DEPLOYMENT_APP_MEMORY_LIMIT:-2G}"
export APP_CPU_LIMIT="${DEPLOYMENT_APP_CPU_LIMIT:-1.5}"
export APP_MEMORY_RESERVATION="${DEPLOYMENT_APP_MEMORY_RESERVATION:-1G}"
export APP_CPU_RESERVATION="${DEPLOYMENT_APP_CPU_RESERVATION:-0.75}"

export QUEUE_MEMORY_LIMIT="${DEPLOYMENT_QUEUE_MEMORY_LIMIT:-1G}"
export QUEUE_CPU_LIMIT="${DEPLOYMENT_QUEUE_CPU_LIMIT:-0.5}"
export QUEUE_MEMORY_RESERVATION="${DEPLOYMENT_QUEUE_MEMORY_RESERVATION:-512M}"
export QUEUE_CPU_RESERVATION="${DEPLOYMENT_QUEUE_CPU_RESERVATION:-0.25}"

export QUEUE_SLEEP="${DEPLOYMENT_QUEUE_SLEEP:-1}"
export QUEUE_TRIES="${DEPLOYMENT_QUEUE_TRIES:-3}"
export QUEUE_MAX_TIME="${DEPLOYMENT_QUEUE_MAX_TIME:-3600}"

export ROUTER_PRIORITY="150"
export HEALTH_ROUTER_PRIORITY="200"

log "All deployment variables configured ✓"

if [ ! -f "docker-compose.prod.yml" ]; then
    error "docker-compose.prod.yml not found"
    exit 1
fi

if [ ! -f "scripts/deploy/compose-stack.sh" ]; then
    error "scripts/deploy/compose-stack.sh not found"
    exit 1
fi

touch .env
upsert_env_var .env DEPLOYMENT_COLOR "$DEPLOYMENT_COLOR"
upsert_env_var .env DEPLOYMENT_TIMESTAMP "$DEPLOYMENT_TIMESTAMP"

TEMP_COMPOSE_FILE="docker-compose.yml.tmp.$(date +%s).$$"

log "=========================================="
log "STEP 1: GENERATING COMPOSE FILE"
log "=========================================="
log "Target temporary file: $TEMP_COMPOSE_FILE"

if ! bash scripts/deploy/compose-stack.sh render-blue-green docker-compose.prod.yml "$TEMP_COMPOSE_FILE" "$NEW_COLOR"; then
    error "Failed to render docker-compose.yml"
    rm -f "$TEMP_COMPOSE_FILE" 2>/dev/null || true
    exit 1
fi

log "✓ Temporary compose file generated successfully"
log "File exists check: $([ -f "$TEMP_COMPOSE_FILE" ] && echo 'YES' || echo 'NO')"
if [ -f "$TEMP_COMPOSE_FILE" ]; then
    log "File size: $(ls -lh "$TEMP_COMPOSE_FILE")"
fi

log "=========================================="
log "STEP 2: VALIDATION AND REPLACEMENT"
log "=========================================="

if ! docker compose -f "$TEMP_COMPOSE_FILE" config >/dev/null 2>&1; then
    error "Rendered docker-compose file is invalid"
    docker compose -f "$TEMP_COMPOSE_FILE" config || true
    rm -f "$TEMP_COMPOSE_FILE" 2>/dev/null || true
    exit 1
fi

while IFS= read -r expected_service; do
    [ -n "$expected_service" ] || continue

    if ! bash scripts/deploy/compose-stack.sh has-service "$TEMP_COMPOSE_FILE" "$expected_service" >/dev/null 2>&1; then
        error "Expected generated service missing: $expected_service"
        log "Rendered top-level services in temporary compose:"
        docker run --rm -v "$PWD":/workdir -w /workdir mikefarah/yq eval '.services | keys | .[]' "$TEMP_COMPOSE_FILE" 2>/dev/null | sed 's/^/  - /' || true
        rm -f "$TEMP_COMPOSE_FILE" 2>/dev/null || true
        exit 1
    fi
done < <(bash scripts/deploy/compose-stack.sh list-blue-green docker-compose.prod.yml "$NEW_COLOR")

if [ -f docker-compose.yml ]; then
    backup_file="docker-compose.yml.backup.$(date +%Y%m%d_%H%M%S)"
    cp docker-compose.yml "$backup_file"
    ls -t docker-compose.yml.backup.* 2>/dev/null | tail -n +6 | xargs rm -f 2>/dev/null || true
    log "Backup created: $backup_file"
fi

mv "$TEMP_COMPOSE_FILE" docker-compose.yml

log "=========================================="
log "✅ PRODUCTION COMPOSE GENERATION COMPLETED"
log "=========================================="
log "📄 File: docker-compose.yml"
log "🎯 Deployment color: $NEW_COLOR"
log "⏱️ Timestamp: $DEPLOYMENT_TIMESTAMP"
log ""
log "Next steps:"
log "  1. Generate only the secrets required by the rendered compose"
log "  2. Start infrastructure and colorized runtime services"
log "  3. Perform zero-downtime traffic switch"
log "=========================================="
@endtask

@task('upload_cf_token', ['on' => 'local', 'force' => false])
    printf '\033[0;32m[INFO] Starting Cloudflare token deployment...\033[0m\n'

    if ! bash scripts/deploy/compose-stack.sh has-secret deploy/docker-compose/docker-compose.prod.yml cf >/dev/null 2>&1; then
        printf '\033[0;34m[INFO] Cloudflare token not required by current compose, skipping\033[0m\n'
        exit 0
    fi

    LOCAL_ENV_CF=".env.cf"
    ZONE_NAME="{{ $site_name }}"

    SECRET_DIR="/var/secrets/{{ $site_name }}"
    REMOTE_TOKEN_FILE="$SECRET_DIR/cf.txt"
    REMOTE_TMP_FILE="$SECRET_DIR/cf.txt.tmp"
    VERIFY_BODY=$(mktemp)

    verify_zone_token() {
        VERIFY_TOKEN="$1"

        VERIFY_ZONE_NAME="$ZONE_NAME"
        VERIFY_STATUS=""

        while true; do
            VERIFY_ZONE_ID=$(curl -sS -X GET "https://api.cloudflare.com/client/v4/zones?name=${VERIFY_ZONE_NAME}" \
                -H "Authorization: Bearer ${VERIFY_TOKEN}" \
                -H "Content-Type: application/json" | jq -r '.result[0].id // empty')

            if [ -n "$VERIFY_ZONE_ID" ] && [ "$VERIFY_ZONE_ID" != "null" ]; then
                VERIFY_STATUS=$(curl -sS -o "$VERIFY_BODY" -w '%{http_code}' -X GET "https://api.cloudflare.com/client/v4/zones/${VERIFY_ZONE_ID}" \
                    -H "Authorization: Bearer ${VERIFY_TOKEN}" \
                    -H "Content-Type: application/json" 2>/dev/null || true)
                VERIFY=$(cat "$VERIFY_BODY" 2>/dev/null || true)

                if [ "$VERIFY_STATUS" = "200" ] && printf '%s' "$VERIFY" | grep -Eq '"success"[[:space:]]*:[[:space:]]*true'; then
                    printf '\033[0;32m[INFO] Cloudflare token validated against zone: %s\033[0m\n' "$VERIFY_ZONE_NAME"
                    return 0
                fi
            fi

            case "$VERIFY_ZONE_NAME" in
                *.*) VERIFY_ZONE_NAME=${VERIFY_ZONE_NAME#*.} ;;
                *) break ;;
            esac
        done

        return 1
    }

    if [ ! -f "$LOCAL_ENV_CF" ]; then
        printf '\033[0;31m[ERROR] %s not found\033[0m\n' "$LOCAL_ENV_CF"
        rm -f "$VERIFY_BODY"
        exit 1
    fi

    TMP_CF=$(mktemp)
    sed '1s/^\xEF\xBB\xBF//' "$LOCAL_ENV_CF" | grep -vE '^[[:space:]]*($|#)' | sed 's/\r$//' > "$TMP_CF"

    TOKEN_LINE=$(sed -n '1p' "$TMP_CF" | sed -e 's/^[[:space:]]*//; s/[[:space:]]*$//')

    rm -f "$TMP_CF"

    if [ -z "$TOKEN_LINE" ]; then
        printf '\033[0;31m[ERROR] %s must contain Cloudflare token in the first non-empty line\033[0m\n' "$LOCAL_ENV_CF"
        rm -f "$VERIFY_BODY"
        exit 1
    fi

    RAW_TOKEN_LINE="$TOKEN_LINE"

    case "$RAW_TOKEN_LINE" in
        export[[:space:]]CF_API_TOKEN=*)
            NEW_TOKEN=$(printf '%s' "$RAW_TOKEN_LINE" | sed 's/^export[[:space:]]*CF_API_TOKEN=//')
            ;;
        CF_API_TOKEN=*)
            NEW_TOKEN=${RAW_TOKEN_LINE#CF_API_TOKEN=}
            ;;
        *)
            NEW_TOKEN=$RAW_TOKEN_LINE
            ;;
    esac

    NEW_TOKEN=$(printf '%s' "$NEW_TOKEN" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')

    case "$NEW_TOKEN" in
        \"*\")
            NEW_TOKEN=${NEW_TOKEN#\"}
            NEW_TOKEN=${NEW_TOKEN%\"}
            ;;
        \'*\')
            NEW_TOKEN=${NEW_TOKEN#\'}
            NEW_TOKEN=${NEW_TOKEN%\'}
            ;;
    esac

    if [ -z "$NEW_TOKEN" ]; then
        printf '\033[0;31m[ERROR] CF_API_TOKEN is empty after parsing\033[0m\n'
        rm -f "$VERIFY_BODY"
        exit 1
    fi

    TMP_LOCAL=$(mktemp)
    trap 'rm -f "$TMP_LOCAL" "$VERIFY_BODY"' 0

    printf '%s' "$NEW_TOKEN" > "$TMP_LOCAL"

    TOKEN_SUFFIX=$(printf '%s\n' "$NEW_TOKEN" | awk '{ len=length($0); start=(len > 4 ? len - 3 : 1); print substr($0, start, 4) }')
    printf '\033[0;32m[INFO] New token detected (suffix ****%s)\033[0m\n' "$TOKEN_SUFFIX"

    printf '\033[0;32m[INFO] Verifying token via Cloudflare zone API for %s...\033[0m\n' "$ZONE_NAME"

    if ! verify_zone_token "$NEW_TOKEN"; then
        if [ -n "$VERIFY_STATUS" ]; then
            printf '\033[0;31m[ERROR] Cloudflare verification failed with HTTP %s\033[0m\n' "$VERIFY_STATUS"
        fi
        printf '\033[0;31m[ERROR] New Cloudflare token is invalid for zone %s. Aborting deployment.\033[0m\n' "$ZONE_NAME"
        exit 1
    fi

    printf '\033[0;32m[INFO] Token is valid\033[0m\n'

    LOCAL_HASH=$(sha256sum "$TMP_LOCAL" | cut -d ' ' -f1)

    printf '\033[0;32m[INFO] Preparing remote secrets directory...\033[0m\n'

    ssh -i {{ env('SSH_KEY_PATH') }} -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no {{ 'deploy@' . env('SERVER_IP') }} "sudo mkdir -p '$SECRET_DIR' && sudo chown deploy:deploy '$SECRET_DIR' && sudo chmod 700 '$SECRET_DIR'" || {
        printf '\033[0;31m[ERROR] Failed to prepare remote secrets directory\033[0m\n'
        exit 1
    }

    printf '\033[0;32m[INFO] Checking remote token...\033[0m\n'

    # Capture stderr as well so remote diagnostics are visible in logs
    ssh -T -i {{ env('SSH_KEY_PATH') }} -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no {{ 'deploy@' . env('SERVER_IP') }} 'bash -s' 2>&1 << EOF
TOKEN_FILE="$REMOTE_TOKEN_FILE"
ZONE_NAME="$ZONE_NAME"
LOCAL_HASH="$LOCAL_HASH"

if [ -f "$TOKEN_FILE" ]; then
    REMOTE_HASH=\$(sha256sum "$TOKEN_FILE" | cut -d ' ' -f1)
    REMOTE_TOKEN=\$(cat "$TOKEN_FILE")
    REMOTE_VERIFY_ZONE_NAME="\${ZONE_NAME}"
    REMOTE_VERIFY_STATUS=""
    REMOTE_ZONE_ID=""
    REMOTE_VALID=0

    while true; do
        REMOTE_ZONE_ID=\$(curl -sS -X GET "https://api.cloudflare.com/client/v4/zones?name=\${REMOTE_VERIFY_ZONE_NAME}" \
            -H "Authorization: Bearer \${REMOTE_TOKEN}" \
            -H "Content-Type: application/json" | jq -r '.result[0].id // empty')

        if [ -n "\$REMOTE_ZONE_ID" ] && [ "\$REMOTE_ZONE_ID" != "null" ]; then
            REMOTE_VERIFY_STATUS=\$(curl -sS -o /tmp/cf-verify-body.json -w '%{http_code}' -X GET "https://api.cloudflare.com/client/v4/zones/\${REMOTE_ZONE_ID}" \
                -H "Authorization: Bearer \${REMOTE_TOKEN}" \
                -H "Content-Type: application/json" 2>/dev/null || true)

            if [ "\$REMOTE_VERIFY_STATUS" = "200" ] && grep -Eq '"success"[[:space:]]*:[[:space:]]*true' /tmp/cf-verify-body.json; then
                REMOTE_VALID=1
                if [ "$LOCAL_HASH" = "\$REMOTE_HASH" ]; then
                    echo "[INFO] Token already up to date and valid"
                    rm -f /tmp/cf-verify-body.json
                    exit 3
                fi

                echo "[INFO] Existing token is valid but differs from the new validated token. It will be replaced."
                rm -f /tmp/cf-verify-body.json
                exit 0
            fi

            rm -f /tmp/cf-verify-body.json
        fi

        case "\$REMOTE_VERIFY_ZONE_NAME" in
            *.*) REMOTE_VERIFY_ZONE_NAME=\${REMOTE_VERIFY_ZONE_NAME#*.} ;;
            *) break ;;
        esac
    done

    echo "[INFO] Existing token is missing, invalid, or differs from the new validated token. It will be replaced."
fi
EOF

    SSH_EXIT=$?

    if [ "$SSH_EXIT" = "3" ]; then
        printf '\033[0;32m[INFO] Remote token already matches local token. Skipping.\033[0m\n'
        exit 0
    fi

    if [ "$SSH_EXIT" != "0" ]; then
        printf '\033[0;31m[ERROR] Failed to inspect remote token state\033[0m\n'
        exit 1
    fi

    printf '\033[0;32m[INFO] Uploading token...\033[0m\n'

    scp -i {{ env('SSH_KEY_PATH') }} -P {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no \
    "$TMP_LOCAL" {{ 'deploy@' . env('SERVER_IP') }}:"$REMOTE_TMP_FILE" || {
        printf '\033[0;31m[ERROR] Upload failed\033[0m\n'
        exit 1
    }

    ssh -i {{ env('SSH_KEY_PATH') }} -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no {{ 'deploy@' . env('SERVER_IP') }} "
SECRET_DIR='$SECRET_DIR'
TOKEN_FILE='$REMOTE_TOKEN_FILE'
TMP_FILE='$REMOTE_TMP_FILE'

sudo mkdir -p \"\$SECRET_DIR\"
sudo chmod 700 \"\$SECRET_DIR\"

if [ -f \"\$TOKEN_FILE\" ]; then
    sudo cp \"\$TOKEN_FILE\" \"\$TOKEN_FILE.bak\"
fi

sudo mv \"\$TMP_FILE\" \"\$TOKEN_FILE\"

sudo chown deploy:deploy \"\$TOKEN_FILE\"
sudo chmod 600 \"\$TOKEN_FILE\"
" || {
        printf '\033[0;31m[ERROR] Failed to install token on remote server\033[0m\n'
        exit 1
    }

    printf '\033[0;32m[SUCCESS] Cloudflare token deployed successfully\033[0m\n'
@endtask

@task('calculate_workers', ['on' => 'web_new'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Calculating optimal workers...\033[0m"

    if [ ! -f {{ $remote_html_path }}/.env ]; then
        echo -e "\033[0;31m[ERROR] {{ $remote_html_path }}/.env not found\033[0m"
        exit 1
    fi

    CPU_COUNT=$(nproc)
    echo -e "\033[0;34m[INFO] Detected $CPU_COUNT CPU cores\033[0m"

    TOTAL_MEMORY=$(free -m | awk '/^Mem:/{print $2}')
    echo -e "\033[0;34m[INFO] Total memory: $TOTAL_MEMORY MB\033[0m"

    OCTANE_MEMORY_PER_WORKER=200
    QUEUE_MEMORY_PER_WORKER=50
    RESERVED_MEMORY=2500

    DOCKER_MEMORY=$(docker stats --no-stream --format "@{{.MemUsage}}" | awk -F'[^0-9.]+' '{sum+=$1} END {print int(sum)}')
    if [ -n "$DOCKER_MEMORY" ]; then
        RESERVED_MEMORY=$(( $RESERVED_MEMORY + $DOCKER_MEMORY ))
        echo -e "\033[0;34m[INFO] Additional Docker memory usage: $DOCKER_MEMORY MB\033[0m"
    fi

    QUEUE_WORKERS=$(grep -E '^QUEUE_WORKERS=' {{ $remote_html_path }}/.env | cut -d '=' -f2 || echo 0)
    if ! [[ "$QUEUE_WORKERS" =~ ^[0-9]+$ ]]; then
        QUEUE_WORKERS=0
        echo -e "\033[0;34m[INFO] Invalid QUEUE_WORKERS, defaulting to 0\033[0m"
    fi
    if [ "$QUEUE_WORKERS" -eq 0 ]; then
        echo -e "\033[0;34m[INFO] Queues are disabled (QUEUE_WORKERS=0)\033[0m"
    fi

    AVAILABLE_MEMORY=$(( $TOTAL_MEMORY - $RESERVED_MEMORY - ($QUEUE_WORKERS * $QUEUE_MEMORY_PER_WORKER) ))
    MAX_OCTANE_WORKERS=$(( $AVAILABLE_MEMORY / $OCTANE_MEMORY_PER_WORKER ))
    if [ $MAX_OCTANE_WORKERS -lt $CPU_COUNT ]; then
        OCTANE_WORKERS=$MAX_OCTANE_WORKERS
    else
        OCTANE_WORKERS=$CPU_COUNT
    fi
    [ $OCTANE_WORKERS -lt 1 ] && OCTANE_WORKERS=1
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Recommended Octane workers: $OCTANE_WORKERS\033[0m"

    if [ "$QUEUE_WORKERS" -ne 0 ]; then
        MAX_QUEUE_WORKERS=$(( ($AVAILABLE_MEMORY - ($OCTANE_WORKERS * $OCTANE_MEMORY_PER_WORKER)) / $QUEUE_MEMORY_PER_WORKER ))
        QUEUE_WORKERS=$(( $MAX_QUEUE_WORKERS > $CPU_COUNT * 2 ? $CPU_COUNT * 2 : $MAX_QUEUE_WORKERS ))
        if [ $QUEUE_WORKERS -lt 1 ]; then
            QUEUE_WORKERS=1
        fi
        echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Recommended Queue workers: $QUEUE_WORKERS\033[0m"
        echo -e "\033[0;33m[WARNING] Review QUEUE_WORKERS limit (CPU_COUNT * 2 = $(( $CPU_COUNT * 2 ))) if CPU or memory increased\033[0m"
    fi

    cp {{ $remote_html_path }}/.env {{ $remote_html_path }}/.env.bak
    if grep -q "OCTANE_WORKERS=" {{ $remote_html_path }}/.env; then
        sed -i "s/OCTANE_WORKERS=.*/OCTANE_WORKERS=$OCTANE_WORKERS/" {{ $remote_html_path }}/.env
    else
        echo "OCTANE_WORKERS=$OCTANE_WORKERS" >> {{ $remote_html_path }}/.env
    fi
    if [ "$QUEUE_WORKERS" -ne 0 ]; then
        if grep -q "QUEUE_WORKERS=" {{ $remote_html_path }}/.env; then
            sed -i "s/QUEUE_WORKERS=.*/QUEUE_WORKERS=$QUEUE_WORKERS/" {{ $remote_html_path }}/.env
        else
            echo "QUEUE_WORKERS=$QUEUE_WORKERS" >> {{ $remote_html_path }}/.env
        fi
    fi

    echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Note: Containers need to be restarted later to apply updated worker settings\033[0m"

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Updated OCTANE_WORKERS and QUEUE_WORKERS in {{ $remote_html_path }}/.env ✓\033[0m"
@endtask

@task('build_containers', ['on' => 'web_new'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Building all containers...\033[0m"

    set -o allexport
        source <(grep -v '^#' {{ $remote_html_path }}/.env | sed 's/^export //')
    set +o allexport

    cd {{ $remote_html_path }} && docker compose build --no-cache

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] All containers built successfully ✓\033[0m"
@endtask

@task('prepare_docker_volumes', ['on' => 'web_new'])
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] Checking required Docker volumes..."

    for VOLUME in haproxy-config haproxy-certs
    do
        if docker volume inspect "$VOLUME" >/dev/null 2>&1; then
            echo "✔ Volume $VOLUME already exists"
        else
            echo "➕ Creating missing volume: $VOLUME"
            docker volume create "$VOLUME"
        fi
    done

    echo "Docker volume check completed ✓"
@endtask

@task('sync_reverse_proxy_network_range', ['on' => 'web_new'])

    NETWORK_NAME="reverse_proxy"
    DESIRED_SUBNET="172.30.0.0/24"
    LOCK_DIR="$HOME/.deploy_locks"
    LOCK_FILE="$LOCK_DIR/reverse_proxy_network.lock"
    TIMESTAMP=$(date +"%Y-%m-%d %H:%M:%S")
    SITES_ROOT="{{ $sites_root }}"

    mkdir -p "$LOCK_DIR"

    # Acquire deployment lock
    exec 200>"$LOCK_FILE"
    flock -n 200 || {
        echo "[$TIMESTAMP] Another deployment is running. Exiting."
        exit 1
    }

    echo "[$TIMESTAMP] Checking network: $NETWORK_NAME"

    # Get current subnet (empty if network does not exist)
    CURRENT_SUBNET=$(docker network inspect "$NETWORK_NAME" \
        --format '@{{(index .IPAM.Config 0).Subnet}}' 2>/dev/null) || true

    echo "[$TIMESTAMP] Current subnet: ${CURRENT_SUBNET:-none}"

    # If network does not exist, create it
    if [ -z "$CURRENT_SUBNET" ]; then
        echo "[$TIMESTAMP] Network does not exist. Creating with subnet $DESIRED_SUBNET..."

        docker network create --subnet="$DESIRED_SUBNET" "$NETWORK_NAME" || {
            echo "[$TIMESTAMP] Failed to create network"
            exit 1
        }

        echo "[$TIMESTAMP] Network created."
        exit 0
    fi

    # If subnet already correct, nothing to do
    if [ "$CURRENT_SUBNET" = "$DESIRED_SUBNET" ]; then
        echo "[$TIMESTAMP] Network subnet already correct: $CURRENT_SUBNET"
        exit 0
    fi

    echo "[$TIMESTAMP] Subnet mismatch. Current: $CURRENT_SUBNET, Target: $DESIRED_SUBNET"
    echo "[$TIMESTAMP] Starting migration..."

    # Identify active projects
    ACTIVE_PROJECTS=()

    for d in "$SITES_ROOT"/*/; do
        [ -f "$d/docker-compose.yml" ] || continue

        DIR_NAME=$(basename "$d")

        case "$DIR_NAME" in
            *.backup|html) continue ;;
        esac

        ACTIVE_PROJECTS+=("$d")
    done

    # Stop projects
    echo "[$TIMESTAMP] Stopping projects..."
    for proj_dir in "${ACTIVE_PROJECTS[@]}"; do
        echo "[$TIMESTAMP] Stopping $(basename "$proj_dir")..."
        (cd "$proj_dir" && docker compose down)
    done

    # Force disconnect any remaining containers (safe)
    if docker network inspect "$NETWORK_NAME" >/dev/null 2>&1; then
        docker network inspect "$NETWORK_NAME" \
            -f '@{{range .Containers}}@{{.Name}} @{{end}}' | \
            xargs -r -n1 docker network disconnect -f "$NETWORK_NAME" 2>/dev/null || true
    fi

    # Remove old network
    echo "[$TIMESTAMP] Removing old network..."
    docker network rm "$NETWORK_NAME" 2>/dev/null || true

    # Recreate network
    echo "[$TIMESTAMP] Creating network with correct subnet..."
    docker network create --subnet="$DESIRED_SUBNET" "$NETWORK_NAME" || {
        echo "[$TIMESTAMP] Failed to recreate network"
        exit 1
    }

    # Restart projects
    echo "[$TIMESTAMP] Restarting projects..."
    for proj_dir in "${ACTIVE_PROJECTS[@]}"; do
        echo "[$TIMESTAMP] Starting $(basename "$proj_dir")..."
        (cd "$proj_dir" && docker compose up -d)
    done

    echo "[$TIMESTAMP] Network migration completed successfully."

@endtask


@task('start_containers', ['on' => 'web_new'])
    set -e # Terminate on any error

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Starting containers...\033[0m"

    # Load environment variables
    set -o allexport
        source <(grep -v '^#' {{ $remote_html_path }}/.env | sed 's/^export //')
    set +o allexport

    # Determine Blue/Green color
    if docker compose -f {{ $remote_html_path }}/docker-compose.yml config --services | grep -q "^app-blue$"; then
        NEW_COLOR="blue"
        OLD_COLOR="green"
    elif docker compose -f {{ $remote_html_path }}/docker-compose.yml config --services | grep -q "^app-green$"; then
        NEW_COLOR="green"
        OLD_COLOR="blue"
    else
        echo -e "\033[0;31m[ERROR] Could not determine deployment color\033[0m"
        exit 1
    fi

    echo -e "\033[0;34mTarget color: $NEW_COLOR\033[0m"
    echo -e "\033[0;34mOld color: $OLD_COLOR\033[0m"

    echo {{ $container_prefix }}

    OLD_CONTAINERS=$(docker ps -a --format '@{{.Names}}' \
    | grep "^{{ $container_prefix }}" \
    | grep "${OLD_COLOR}") || true

    if [ -n "$OLD_CONTAINERS" ]; then
        echo "Removing old containers:"
        echo "$OLD_CONTAINERS"
        docker rm -f $OLD_CONTAINERS
    else
        echo "No old containers to remove"
    fi

    echo "Waiting for resources to be released..."
    sleep 5

    # Start the new stack
    cd {{ $remote_html_path }} && docker compose up -d --build --remove-orphans

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Waiting for services to stabilize...\033[0m"

    # 1. Verify Octane (PHP)
    is_octane_running() {
        docker compose exec -T app-$NEW_COLOR sh -lc '
            if command -v pgrep >/dev/null 2>&1; then
                pgrep -f "artisan octane:start" >/dev/null 2>&1 && exit 0
            fi

            if command -v ps >/dev/null 2>&1; then
                ps -ef 2>/dev/null | grep -q "[a]rtisan octane:start" && exit 0
                ps 2>/dev/null | grep -q "[a]rtisan octane:start" && exit 0
            fi

            exit 1
        ' </dev/null >/dev/null 2>&1
    }

    ATTEMPTS=0
    until is_octane_running || [ $ATTEMPTS -eq 15 ]; do
        echo "Waiting for Octane process... ($((ATTEMPTS+1))/15)"
        sleep 2
        ATTEMPTS=$((ATTEMPTS+1))
    done

    if ! is_octane_running; then
        echo -e "\033[0;31m❌ ERROR: Octane failed to start\033[0m"
        exit 1
    fi

    # 2. Verify Database Connectivity
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Verifying database connectivity...\033[0m"
    if ! OUTPUT=$(docker compose exec -T --user root app-$NEW_COLOR sh -lc '
        if [ -n "${APP_KEY:-}" ] && [ -f "${APP_KEY}" ]; then
            export APP_KEY="$(cat "${APP_KEY}")"
        elif [ -f /run/secrets/app_key ]; then
            export APP_KEY="$(cat /run/secrets/app_key)"
        fi

        if [ -n "${DB_PASSWORD:-}" ] && [ -f "${DB_PASSWORD}" ]; then
            export DB_PASSWORD="$(cat "${DB_PASSWORD}")"
        elif [ -f /run/secrets/db_password ]; then
            export DB_PASSWORD="$(cat /run/secrets/db_password)"
        fi

        exec gosu www-data php artisan db:show
    ' </dev/null 2>&1); then
        echo -e "\033[1;31m[ERROR] Database check failed:\033[0m"
        echo "$OUTPUT"
        exit 1
    fi
    echo -e "\033[0;32m✅ Database connection successful\033[0m"

    # 3. Verify SeaweedFS only when it is part of the rendered stack
    if docker compose config --services | grep -qx "seaweedfs-$NEW_COLOR"; then
        echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Waiting for SeaweedFS S3 API (Port 8333)...\033[0m"

        SW_ATTEMPTS=0
        until docker compose exec -T seaweedfs-$NEW_COLOR curl -sf http://127.0.0.1:8333/ </dev/null >/dev/null || [ $SW_ATTEMPTS -eq 20 ]; do
            echo "Waiting for S3 API to respond... ($((SW_ATTEMPTS+1))/30)"
            sleep 2
            SW_ATTEMPTS=$((SW_ATTEMPTS+1))
        done

        if docker compose exec -T seaweedfs-$NEW_COLOR curl -sf http://127.0.0.1:8888/ </dev/null >/dev/null; then
            echo -e "\033[0;32m✅ SeaweedFS Filer is responding\033[0m"
        else
            echo -e "\033[0;31m❌ ERROR: SeaweedFS Filer is not responding\033[0m"
            docker compose logs seaweedfs-$NEW_COLOR | tail -n 20
            exit 1
        fi
    else
        echo -e "\033[0;34m[INFO] SeaweedFS is not part of the rendered stack, skipping check\033[0m"
    fi

    # Start other site containers
    echo "Starting other site containers..."
    for d in {{ $sites_root }}/*/; do
        [ -f "$d/docker-compose.yml" ] || continue
        SITE_DIR=$(realpath "$d")

        # Skip reverse-proxy
        [ "$SITE_DIR" = "$(realpath "{{ $sites_root }}/{{ $reverse_proxy_folder_name }}")" ] && continue

        # Skip backup and removed directories
        case "$(basename "$SITE_DIR")" in *.backup|*.removed.*) continue ;; esac

        # Skip current site (already started above)
        [ "$SITE_DIR" = "$(realpath "{{ $remote_html_path }}")" ] && continue

        echo "Starting containers in: $SITE_DIR"
        docker compose -f "$SITE_DIR/docker-compose.yml" start 2>/dev/null || true
    done
    echo "Other site containers started"

    # Cleanup backup
    sudo rm -rf {{ $remote_html_path }}.backup 2>/dev/null || true

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] ✅ All systems go!\033[0m"
    echo -e "\033[0;32m🌐 Site: https://{{ env('DOMAIN') }}\033[0m"
@endtask

@task('tune_postgres', ['on' => 'web_new'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Applying PostgreSQL performance tuning using container environment...\033[0m"

    if ! docker compose config --services | grep -qx "postgres"; then
        echo -e "\033[0;34m[INFO] Postgres service is not part of the rendered stack, skipping tuning\033[0m"
        exit 0
    fi

    POSTGRES_CONTAINER=$(docker ps -q -f name=^{{ $container_prefix }}postgres)
    if [ -z "$POSTGRES_CONTAINER" ]; then
        echo -e "\033[0;31m[ERROR] Postgres container not running\033[0m"
        exit 1
    fi

    TOTAL_MEM=$(free -m | awk 'NR==2{printf "%.0f", $2/1024}')
    SHARED_MEM=$(df -BM /dev/shm | awk 'NR==2{print $2}' | sed 's/M//')
    echo -e "\033[0;33m[INFO] System Memory: ${TOTAL_MEM}GB, Shared Memory: ${SHARED_MEM}MB\033[0m"

    CONTAINER_MEM_BYTES=$(docker inspect "$POSTGRES_CONTAINER" --format='@{{.HostConfig.Memory}}')
    CONTAINER_MEM_GB=$((CONTAINER_MEM_BYTES / 1024 / 1024 / 1024))

    SHARED_BUFFERS_MB=$((CONTAINER_MEM_GB * 256))
    if [ $SHARED_BUFFERS_MB -gt 1024 ]; then
        SHARED_BUFFERS_MB=1024
    fi

    MAX_SHARED_MEM=$((SHARED_MEM * 3 / 4))
    if [ $SHARED_BUFFERS_MB -gt $MAX_SHARED_MEM ]; then
        SHARED_BUFFERS_MB=$MAX_SHARED_MEM
        echo -e "\033[0;33m[WARNING] Reducing shared_buffers due to shared memory constraints\033[0m"
    fi

    echo -e "\033[0;33m[INFO] Container Memory: ${CONTAINER_MEM_GB}GB\033[0m"

    declare -A settings=(
        [shared_buffers]="${SHARED_BUFFERS_MB}MB"
        [work_mem]="16MB"
        [maintenance_work_mem]="256MB"
        [effective_cache_size]="$((CONTAINER_MEM_GB * 3 / 4))GB"
        [max_connections]="100"
        [wal_buffers]="16MB"
        [checkpoint_completion_target]="0.9"
        [random_page_cost]="1.1"
        [max_parallel_workers_per_gather]="2"
        [max_parallel_workers]="4"
        [max_worker_processes]="8"
        [checkpoint_timeout]="10min"
        [max_wal_size]="2GB"
        [min_wal_size]="512MB"
        [log_min_duration_statement]="1000"
        [log_checkpoints]="on"
        [log_connections]="on"
        [log_disconnections]="on"
        [log_lock_waits]="on"
    )

    echo -e "\033[0;33m[INFO] Using shared_buffers: ${settings[shared_buffers]}\033[0m"

    PG_VERSION=$(docker exec "$POSTGRES_CONTAINER" sh -c "
      PGPASSWORD=\$(cat \$POSTGRES_PASSWORD_FILE) \
      psql -U \$POSTGRES_USER -d \$POSTGRES_DB -t -c \"SELECT version();\"" | head -1)
    echo -e "\033[0;33m[INFO] PostgreSQL Version: $PG_VERSION\033[0m"

    echo -e "\033[0;32m[INFO] Applying configuration settings...\033[0m"
    for key in "${!settings[@]}"; do
        value="${settings[$key]}"
        echo "Setting $key = $value"
        docker exec "$POSTGRES_CONTAINER" sh -c "
          PGPASSWORD=\$(cat \$POSTGRES_PASSWORD_FILE) \
          psql -U \$POSTGRES_USER -d \$POSTGRES_DB -c \"ALTER SYSTEM SET $key = '$value';\"" || {
            echo -e "\033[0;31m[ERROR] Failed to set $key\033[0m"
            exit 1
        }
    done

    echo -e "\033[0;33m[INFO] Current key settings before reload:\033[0m"
    docker exec "$POSTGRES_CONTAINER" sh -c "
      PGPASSWORD=\$(cat \$POSTGRES_PASSWORD_FILE) \
      psql -U \$POSTGRES_USER -d \$POSTGRES_DB -c \"
        SELECT name, setting, unit, context
        FROM pg_settings
        WHERE name IN ('shared_buffers', 'work_mem', 'max_connections', 'effective_cache_size')
        ORDER BY name;\"" || true

    echo -e "\033[0;32m[INFO] Reloading PostgreSQL configuration...\033[0m"
    docker exec "$POSTGRES_CONTAINER" sh -c "
      PGPASSWORD=\$(cat \$POSTGRES_PASSWORD_FILE) \
      psql -U \$POSTGRES_USER -d \$POSTGRES_DB -c \"SELECT pg_reload_conf();\"" || {
        echo -e "\033[0;31m[ERROR] Failed to reload PostgreSQL config\033[0m"
        exit 1
    }

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Restarting Postgres container to finalize tuning...\033[0m"
    docker restart "$POSTGRES_CONTAINER" || {
        echo -e "\033[0;31m[ERROR] Failed to restart Postgres container\033[0m"
        exit 1
    }

    echo -e "\033[0;33m[INFO] Waiting for PostgreSQL to start...\033[0m"
    for i in {1..30}; do
        if docker exec "$POSTGRES_CONTAINER" sh -c "
          PGPASSWORD=\$(cat \$POSTGRES_PASSWORD_FILE) \
          psql -U \$POSTGRES_USER -d \$POSTGRES_DB -c \"SELECT 1;\"" >/dev/null 2>&1; then
            echo -e "\033[0;32m[INFO] PostgreSQL is responding\033[0m"
            break
        fi
        echo -n "."
        sleep 2
        if [ $i -eq 30 ]; then
            echo -e "\033[0;31m[ERROR] Postgres is not responding after restart\033[0m"
            echo -e "\033[0;33m[INFO] Container logs:\033[0m"
            docker logs --tail 20 "$POSTGRES_CONTAINER"
            exit 1
        fi
    done

    echo -e "\033[0;32m[INFO] Verifying applied settings:\033[0m"
    docker exec "$POSTGRES_CONTAINER" sh -c "
      PGPASSWORD=\$(cat \$POSTGRES_PASSWORD_FILE) \
      psql -U \$POSTGRES_USER -d \$POSTGRES_DB -c \"
        SELECT name, setting, unit, context, pending_restart
        FROM pg_settings
        WHERE name IN ('shared_buffers', 'work_mem', 'max_connections', 'effective_cache_size', 'wal_buffers')
        ORDER BY name;\"" || {
        echo -e "\033[0;31m[ERROR] Failed to verify settings\033[0m"
        exit 1
    }

    PENDING_RESTART=$(docker exec "$POSTGRES_CONTAINER" sh -c "
      PGPASSWORD=\$(cat \$POSTGRES_PASSWORD_FILE) \
      psql -U \$POSTGRES_USER -d \$POSTGRES_DB -t -c \"
        SELECT COUNT(*) FROM pg_settings WHERE pending_restart = true;\"" | tr -d ' ')

    if [ "$PENDING_RESTART" -gt 0 ]; then
        echo -e "\033[0;33m[WARNING] Some settings still require restart. Consider restarting again.\033[0m"
    fi

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] ✅ PostgreSQL tuning applied successfully!\033[0m"
    echo -e "\033[0;33m[INFO] Monitor performance and adjust settings as needed.\033[0m"
@endtask

@task('dump_local_db', ['on' => 'local'])
    # Print timestamped message
    printf "[%s] Creating PostgreSQL dump from local container...\n" "$(date '+%Y-%m-%d %H:%M:%S')"

    if ! docker compose config --services | grep -qx "postgres"; then
        printf "[%s] Postgres service is not present locally, skipping dump.\n" "$(date '+%Y-%m-%d %H:%M:%S')"
        exit 0
    fi

    printf "Ensuring local Docker containers are running...\n"
    docker compose up -d

    PROJECT_NAME=$(basename "$PWD")

    # Find running Postgres container for this project
    POSTGRES_CONTAINER=$(docker ps \
        --filter "label=com.docker.compose.project=${PROJECT_NAME}" \
        --filter "label=com.docker.compose.service=postgres" \
        --format "@{{.Names}}" \
        | head -n 1)

    # Stop if container not found
    if [ -z "$POSTGRES_CONTAINER" ]; then
        printf "ERROR: No running postgres container found for project %s\n" "$PROJECT_NAME"
        exit 1
    fi

    printf "Postgres container detected: %s\n" "$POSTGRES_CONTAINER"

    # Paths on HOST
    DUMP_RAW="/tmp/{{ $prefix }}-pg-dump.sql"
    DUMP_GZ="${DUMP_RAW}.gz"

    # Run pg_dump inside container and save RAW dump to host
    docker exec -i "$POSTGRES_CONTAINER" sh -c "
        export PGPASSWORD=\$POSTGRES_PASSWORD
        exec pg_dump \
            -U {{ env('DB_USERNAME') }} \
            -d {{ env('DB_DATABASE') }} \
            --no-owner \
            --no-acl \
            --encoding=UTF8 \
            --format=plain
    " > "$DUMP_RAW"

    DUMP_STATUS=$?

    # Check pg_dump result FIRST
    if [ $DUMP_STATUS -ne 0 ]; then
        printf "ERROR: pg_dump failed with exit code %s\n" "$DUMP_STATUS"
        rm -f "$DUMP_RAW"
        exit 1
    fi

    # Compress only if dump succeeded
    gzip -f "$DUMP_RAW"
    GZIP_STATUS=$?

    if [ $GZIP_STATUS -ne 0 ]; then
        printf "ERROR: gzip failed with exit code %s\n" "$GZIP_STATUS"
        rm -f "$DUMP_RAW" "$DUMP_GZ"
        exit 1
    fi

    printf "[%s] Database dump successfully created: %s\n" "$(date '+%Y-%m-%d %H:%M:%S')" "$DUMP_GZ"
@endtask


@task('copy_db_dump', ['on' => 'local'])
    if ! docker compose config --services | grep -qx "postgres"; then
        printf "[%s] Postgres service is not present locally, skipping dump upload.\n" "$(date '+%Y-%m-%d %H:%M:%S')"
        exit 0
    fi

    # Define local and remote paths
    LOCAL_DUMP="/tmp/{{ $prefix }}-pg-dump.sql.gz"
    REMOTE_DUMP="/tmp/{{ $prefix }}-pg-dump.sql.gz"
    REMOTE_USER="{{ 'deploy' }}"
    REMOTE_HOST="{{ env('SERVER_IP') }}"
    SSH_OPTS="-i {{ env('SSH_KEY_PATH') }} -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no"

    # Check if local dump exists
    if [ ! -f "$LOCAL_DUMP" ]; then
        printf "ERROR: Local dump file not found: %s\n" "$LOCAL_DUMP"
        exit 1
    fi

    printf "[%s] Copying archive to remote server...\n" "$(date '+%Y-%m-%d %H:%M:%S')"

    # Copy archive via rsync
    rsync -avh --progress -e "ssh $SSH_OPTS" "$LOCAL_DUMP" "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_DUMP}"
    RSYNC_STATUS=$?

    if [ $RSYNC_STATUS -ne 0 ]; then
        printf "ERROR: rsync failed with exit code %s\n" "$RSYNC_STATUS"
        exit 1
    fi

    printf "[%s] Archive copied successfully. Extracting on remote server...\n" "$(date '+%Y-%m-%d %H:%M:%S')"

    # Extract archive on remote server
    ssh $SSH_OPTS "${REMOTE_USER}@${REMOTE_HOST}" "gunzip -f '$REMOTE_DUMP'"
    SSH_STATUS=$?

    if [ $SSH_STATUS -ne 0 ]; then
        printf "ERROR: Failed to extract archive on remote server (exit code %s)\n" "$SSH_STATUS"
        exit 1
    fi

    rm -rf $LOCAL_DUMP

    printf "[%s] Archive extracted successfully on remote server: %s\n" "$(date '+%Y-%m-%d %H:%M:%S')" "/tmp/{{ $prefix }}-pg-dump.sql"
@endtask


@task('restore_db_dump_on_server', ['on' => 'web_new'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Restoring database dump on server...\033[0m"

    if ! docker compose config --services | grep -qx "postgres"; then
        echo -e "\033[0;34m[INFO] Postgres service is not part of the rendered stack, skipping restore\033[0m"
        exit 0
    fi

    # Check if dump file exists on host
    if [ ! -f /tmp/{{ $prefix }}-pg-dump.sql ]; then
        echo -e "\033[1;31m[ERROR] Dump file /tmp/{{ $prefix }}-pg-dump.sql not found on host\033[0m"
        exit 1
    fi

    # Find Postgres container by compose service label (running or stopped)
    CONTAINER_NAME=$(docker ps -a --filter "label=com.docker.compose.service=postgres" --format "@{{.Names}}" | head -n 1)

    if [ -z "$CONTAINER_NAME" ]; then
        echo "Postgres container not found, creating via docker compose up -d postgres..."
        docker compose up -d postgres || {
            echo -e "\033[1;31m[ERROR] Failed to create Postgres container\033[0m"
            exit 1
        }
        sleep 5
        CONTAINER_NAME=$(docker ps -a --filter "label=com.docker.compose.service=postgres" --format "@{{.Names}}" | head -n 1)
    fi

    if [ -z "$CONTAINER_NAME" ]; then
        echo -e "\033[1;31m[ERROR] Postgres container not found after compose up\033[0m"
        exit 1
    fi

    echo "Found Postgres container: $CONTAINER_NAME"

    # Stop container if running
    if [ "$(docker inspect -f '@{{.State.Running}}' "$CONTAINER_NAME")" = "true" ]; then
        echo "Stopping Postgres container..."
        docker stop "$CONTAINER_NAME" || {
            echo -e "\033[1;31m[ERROR] Failed to stop Postgres container\033[0m"
            exit 1
        }
        echo -e "\033[0;32m✓ Container stopped\033[0m"
    else
        echo "Container is already stopped"
    fi

    # Start container
    echo "Starting Postgres container..."
    docker start "$CONTAINER_NAME" || {
        echo -e "\033[1;31m[ERROR] Failed to start Postgres container\033[0m"
        exit 1
    }

    # Wait for Postgres to be ready
    echo "Waiting for Postgres to be ready..."
    sleep 5

    # Copy dump file into container
    docker cp /tmp/{{ $prefix }}-pg-dump.sql "$CONTAINER_NAME":/tmp/{{ $prefix }}-pg-dump.sql || {
        echo -e "\033[1;31m[ERROR] Failed to copy dump file into container\033[0m"
        exit 1
    }

    # -------------------------------
    # Check if database already has tables
    # -------------------------------
    echo "Checking if database already has tables..."

    TABLE_COUNT=$(docker exec "$CONTAINER_NAME" sh -c '
        PGPASSWORD=$(cat "$POSTGRES_PASSWORD_FILE") \
        psql -h localhost -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "
            SELECT count(*)
            FROM information_schema.tables
            WHERE table_schema = '\''public'\'';
        "
    ')

    TABLE_COUNT=$(echo "$TABLE_COUNT" | tr -d '[:space:]')

    if [ "$TABLE_COUNT" -gt 0 ]; then
        echo -e "\033[1;31m[WARNING] Database is not empty ($TABLE_COUNT tables found). Dump restore skipped.\033[0m"
        SKIP_RESTORE=1
    else
        echo -e "\033[0;32m✓ Database is empty, safe to restore\033[0m"
        SKIP_RESTORE=0
    fi

    # -------------------------------
    # Restore database dump (only if empty)
    # -------------------------------
    if [ "$SKIP_RESTORE" -eq 0 ]; then
        printf "[%s] Restoring database inside container...\n" "$(date '+%Y-%m-%d %H:%M:%S')"

        docker exec "$CONTAINER_NAME" sh -c "
            PGPASSWORD=\$(cat \"\$POSTGRES_PASSWORD_FILE\") \
            psql -h localhost -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" \
            -v ON_ERROR_STOP=1 \
            -f /tmp/{{ $prefix }}-pg-dump.sql
        " > /dev/null 2>&1

        RESTORE_STATUS=$?

        if [ $RESTORE_STATUS -ne 0 ]; then
            printf "\033[1;31m[ERROR] Failed to restore database dump (exit code %s)\033[0m\n" "$RESTORE_STATUS"
            exit 1
        fi

        echo -e "\033[0;32m✓ Database restore completed successfully\033[0m"
    fi

    PASS=$(docker exec "$CONTAINER_NAME" sh -c 'cat "$POSTGRES_PASSWORD_FILE"')
    docker exec "$CONTAINER_NAME" sh -c "
        psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" \
        -c \"ALTER USER \$POSTGRES_USER WITH PASSWORD '$PASS';\"
    "
    # Cleanup: remove dump files
    docker exec "$CONTAINER_NAME" rm -f /tmp/{{ $prefix }}-pg-dump.sql </dev/null
    sudo rm -f /tmp/{{ $prefix }}-pg-dump.sql || {
        echo -e "\033[1;33m[WARNING] Failed to delete dump file on server host\033[0m"
    }

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] ✅ Database dump process finished and cleanup done ✓\033[0m"
@endtask

@task('create_seaweedfs_archive', ['on' => 'local'])
    # Print start message with timestamp
    printf "\033[0;32m[%s] Creating and transferring SeaweedFS archive...\033[0m\n" "$(date '+%Y-%m-%d %H:%M:%S')"

    if ! docker compose config --services | grep -q '^seaweedfs'; then
        printf "\033[0;34m[%s] SeaweedFS is not present locally, skipping archive creation.\033[0m\n" "$(date '+%Y-%m-%d %H:%M:%S')"
        exit 0
    fi

    TMP_DIR="/tmp"
    ARCHIVE_NAME="{{ $prefix }}-seaweedfs_backup.tar.gz"
    ARCHIVE_PATH="$TMP_DIR/$ARCHIVE_NAME"

    # Remove old archive if exists
    rm -f "$ARCHIVE_PATH"

    # Automatically find the SeaweedFS container
    CONTAINER_NAME=$(docker compose ps --format '@{{.Name}}' | grep -i 'seaweedfs' | head -n 1)

    if [ -z "$CONTAINER_NAME" ]; then
        printf "\033[0;31m[ERROR] SeaweedFS container not found\033[0m\n"
        exit 1
    fi

    printf "Found container: %s\n" "$CONTAINER_NAME"

    # Determine the Docker volume mounted at /data
    VOLUME_NAME=$(docker inspect "$CONTAINER_NAME" --format '@{{range .Mounts}}@{{if eq .Destination "/data"}}@{{.Name}}@{{end}}@{{end}}')

    if [ -z "$VOLUME_NAME" ]; then
        printf "\033[0;31m[ERROR] Could not determine SeaweedFS volume name\033[0m\n"
        exit 1
    fi

    printf "Using volume: %s\n" "$VOLUME_NAME"

    # Stop the container if it is running
    WAS_RUNNING="false"
    if [ "$(docker inspect -f '@{{.State.Running}}' "$CONTAINER_NAME")" = "true" ]; then
        WAS_RUNNING="true"
        printf "Stopping SeaweedFS container...\n"
        docker stop "$CONTAINER_NAME" || {
            printf "\033[0;31m[ERROR] Failed to stop container\033[0m\n"
            exit 1
        }
    fi

    # Create archive from the volume with correct UID/GID
    printf "Creating archive...\n"

    USER_ID=$(id -u)
    GROUP_ID=$(id -g)

    docker run --rm \
        -u "$USER_ID:$GROUP_ID" \
        -v "$VOLUME_NAME":/data:ro \
        -v "$TMP_DIR":/backup \
        busybox sh -c "tar -czf /backup/$ARCHIVE_NAME -C /data ." || {
        printf "\033[0;31m[ERROR] Failed to create archive\033[0m\n"
        [ "$WAS_RUNNING" = "true" ] && docker start "$CONTAINER_NAME"
        exit 1
    }

    # Restart the container if it was stopped
    if [ "$WAS_RUNNING" = "true" ]; then
        printf "Restarting SeaweedFS container...\n"
        docker start "$CONTAINER_NAME" || \
            printf "\033[0;33m[WARNING] Failed to restart container\033[0m\n"
    fi

    # Check that archive was actually created
    if [ ! -s "$ARCHIVE_PATH" ]; then
        printf "\033[0;31m[ERROR] Archive is empty or not created\033[0m\n"
        exit 1
    fi

    SIZE=$(du -h "$ARCHIVE_PATH" | cut -f1)
    printf "Archive size: %s\n" "$SIZE"

    # Transfer the archive to production server
    printf "Transferring archive to production...\n"

    SERVER_IP="{{ env('SERVER_IP') }}"

    rsync -av --progress \
        -e "ssh -i {{ env('SSH_KEY_PATH') }} -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null" \
        "$ARCHIVE_PATH" deploy@${SERVER_IP}:/tmp/"$ARCHIVE_NAME" \
        || {
            printf "\033[0;31m[ERROR] Failed to transfer archive\033[0m\n"
            exit 1
        }


    # Remove local archive
    rm -f "$ARCHIVE_PATH"

    # Print completion message
    printf "\033[0;32m[%s] SeaweedFS archive created and transferred successfully ✓\033[0m\n" "$(date '+%Y-%m-%d %H:%M:%S')"
@endtask



@task('extract_seaweedfs_archive', ['on' => 'web_new'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Extracting SeaweedFS archive on production...\033[0m"

    if ! docker compose config --services | grep -Eq '^seaweedfs-(blue|green)$'; then
        echo -e "\033[0;34m[INFO] SeaweedFS is not part of the rendered stack, skipping extraction\033[0m"
        exit 0
    fi

    ARCHIVE_NAME="{{ $prefix }}-seaweedfs_backup.tar.gz"
    ARCHIVE_PATH="/tmp/$ARCHIVE_NAME"

    # Check if archive exists
    if [ ! -f "$ARCHIVE_PATH" ]; then
        echo -e "\033[0;31m[ERROR] Archive file not found: $ARCHIVE_PATH\033[0m"
        exit 1
    fi

    # Find SeaweedFS container
    CONTAINER_NAME=$(docker ps -a --format '@{{.Names}}' | grep -E '(^|[-_]){{ $container_prefix }}seaweedfs($|[-_])' | head -n 1)

    if [ -z "$CONTAINER_NAME" ]; then
        echo -e "\033[0;31m[ERROR] No seaweedfs container found\033[0m"
        exit 1
    fi

    echo "Found seaweedfs container: $CONTAINER_NAME"

    # Stop container before modifying volume
    if [ "$(docker inspect -f '@{{.State.Running}}' "$CONTAINER_NAME")" = "true" ]; then
        echo "Stopping SeaweedFS container..."
        docker stop "$CONTAINER_NAME" || {
            echo -e "\033[0;31m[ERROR] Failed to stop SeaweedFS container\033[0m"
            exit 1
        }
        echo -e "\033[0;32m✓ Container stopped\033[0m"
    else
        echo "Container is already stopped"
    fi

    VOLUME_NAME="{{ $volume_prefix }}seaweedfs-data"
    echo "Using Docker volume: $VOLUME_NAME"

    # Clear existing volume data using temporary container
    echo "Clearing existing volume data..."
    docker run --rm -v "$VOLUME_NAME":/data busybox sh -c "rm -rf /data/*" || {
        echo -e "\033[0;31m[ERROR] Failed to clear volume\033[0m"
        exit 1
    }

    # Extract archive directly to volume using temporary container
    echo "Extracting archive to volume..."
    docker run --rm \
        -v "$VOLUME_NAME":/data \
        -v /tmp:/backup \
        busybox sh -c "tar -xzf /backup/$ARCHIVE_NAME -C /data" || {
        echo -e "\033[0;31m[ERROR] Failed to extract archive to volume\033[0m"
        exit 1
    }

    # Start container
    echo "Starting SeaweedFS container..."
    docker start "$CONTAINER_NAME" || {
        echo -e "\033[0;31m[ERROR] Failed to start SeaweedFS container\033[0m"
        exit 1
    }

    # Wait for SeaweedFS to be ready
    echo "Waiting for SeaweedFS to be ready..."
    sleep 3

    # Cleanup archive
    sudo rm -f "$ARCHIVE_PATH" || {
        echo -e "\033[0;33m[WARNING] Failed to clean up archive\033[0m"
    }

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] ✅ SeaweedFS archive extracted and container started successfully ✓\033[0m"
@endtask

@task('local_assets_building', ['on' => 'local'])
    if ! docker info >/dev/null 2>&1; then
        echo -e "\033[0;31mDocker is not running. Starting Docker service...\033[0m"

        if command -v systemctl >/dev/null 2>&1; then
            sudo systemctl start docker
        elif command -v service >/dev/null 2>&1; then
            sudo service docker start
        else
            echo -e "\033[0;31mCould not automatically start Docker. Please start Docker manually and retry.\033[0m"
            exit 1
        fi

        echo -e "\033[0;33mWaiting for Docker to start...\033[0m"
        while ! docker info >/dev/null 2>&1; do
            sleep 2
            echo -n "."
        done
        echo -e "\n\033[0;32mDocker started successfully!\033[0m"
    else
        echo -e "\033[0;32mDocker is already running.\033[0m"
    fi

    if ! docker compose ps | grep -q "Up"; then
        echo -e "\033[0;33mStarting Docker Compose containers...\033[0m"
        docker compose up -d
        echo -e "\033[0;32mContainers started!\033[0m"
    else
        echo -e "\033[0;32mContainers are already running.\033[0m"
    fi

    echo -e "\033[0;33mUpdating NPM dependencies locally...\033[0m"
    docker compose exec -T laravel.test bash -c "cd /var/www/html && npm install" </dev/null

    echo -e "\033[0;33mBuilding frontend assets locally...\033[0m"
    docker compose exec -T laravel.test bash -c "cd /var/www/html && npm run build" </dev/null

    TIMESTAMP=$(date +%s)
    TEMP_DIR="assets-$TIMESTAMP"

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Preparing atomic assets deployment...\033[0m"

    ssh -i {{ env('SSH_KEY_PATH') }} -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no {{ 'deploy@' . env('SERVER_IP') }} "mkdir -p {{ $remote_html_path }}/public/$TEMP_DIR/" || { echo "Error: Failed to create temporary directory on remote server"; exit 1; }

    echo -e "\033[0;33mCopying built assets to temporary directory...\033[0m"
    SRC_ASSETS="{{ $local_project_root }}/public/assets/"
    SRC_BUILD="{{ $local_project_root }}/public/build/"
    SRC_BUILD_ASSETS="{{ $local_project_root }}/public/build/assets/"

    SELECTED_SRC=""

    if [ -d "$SRC_ASSETS" ]; then
        SELECTED_SRC="$SRC_ASSETS"
    elif [ -d "$SRC_BUILD_ASSETS" ]; then
        SELECTED_SRC="$SRC_BUILD_ASSETS"
    elif [ -d "$SRC_BUILD" ]; then
        SELECTED_SRC="$SRC_BUILD"
    fi

    if [ -z "$SELECTED_SRC" ]; then
        echo -e "\033[0;31m[ERROR] No built assets found in expected locations:\033[0m"
        echo "  - $SRC_ASSETS"
        echo "  - $SRC_BUILD_ASSETS"
        echo "  - $SRC_BUILD"
        echo ""
        echo -e "\033[0;33mLocal public/ contents:\033[0m"
        ls -la "{{ $local_project_root }}/public" || true
        echo -e "\033[0;33mHint: run 'npm run build' inside the app container or run envoy from the project root.\nIf your build outputs to a different folder, update Envoy to include it.\033[0m"
        exit 1
    fi

    rsync -avh --progress -e "ssh -i {{ env('SSH_KEY_PATH') }} -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no" "$SELECTED_SRC" {{ 'deploy@' . env('SERVER_IP') }}:{{ $remote_html_path }}/public/$TEMP_DIR/ || { echo "Error: Failed to copy assets"; exit 1; }

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Performing atomic assets switch...\033[0m"
    ssh -i {{ env('SSH_KEY_PATH') }} -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no {{ 'deploy@' . env('SERVER_IP') }} "
        cd {{ $remote_html_path }}/public/

        if [ -d 'assets' ]; then
            mv assets assets-backup-$TIMESTAMP
        fi

        mv $TEMP_DIR assets

        ls -dt assets-backup-* 2>/dev/null | tail -n +3 | xargs rm -rf 2>/dev/null || true

        echo 'Assets switched successfully'
    " || { echo "Error: Failed to switch assets atomically"; exit 1; }

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Verifying assets deployment...\033[0m"
    ssh -i {{ env('SSH_KEY_PATH') }} -p {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no {{ 'deploy@' . env('SERVER_IP') }} "
        if [ -d '{{ $remote_html_path }}/public/assets' ]; then
            echo 'Assets directory exists ✓'
            ls -la {{ $remote_html_path }}/public/assets/ | head -5
        else
            echo 'ERROR: Assets directory not found after deployment'
            exit 1
        fi
    " || { echo "Error: Assets verification failed"; exit 1; }

    echo -e "\033[0;32mAssets built and deployed atomically ✓\033[0m"
@endtask

@task('deploy_haproxy', ['on' => 'web_new'])

    get_real_compose_volume() {
        local logical_name="$1"

        # Get compose config in JSON; fails on YAML syntax errors
        local full_config
        if ! full_config=$(docker compose config --format json); then
            echo "Error: Invalid docker-compose syntax." >&2
            return 1
        fi

        # Extract volume definition; fails if logical name is missing
        local volume_data
        volume_data=$(echo "$full_config" | jq -e ".volumes.\"$logical_name\"") || {
            echo "Error: Volume '$logical_name' not found in config." >&2
            return 1
        }

        local is_external=$(echo "$volume_data" | jq -r '.external // false')
        local volume_name=""

        if [ "$is_external" = "true" ]; then
            # Use explicit name or fallback to logical name
            volume_name=$(echo "$volume_data" | jq -r '.name // "'"$logical_name"'"')
        else
            # Handle internal volumes with project prefix
            local project_name
            project_name=$(docker compose config --project-name) || return 1

            local custom_name=$(echo "$volume_data" | jq -r '.name // empty')
            if [ -n "$custom_name" ] && [ "$custom_name" != "null" ]; then
                volume_name="$custom_name"
            else
                volume_name="${project_name}_${logical_name}"
            fi
        fi

        # Final check if volume exists in Docker
        if ! docker volume inspect "$volume_name" >/dev/null 2>&1; then
            echo "Error: Docker volume '$volume_name' not found." >&2
            return 1
        fi

        echo "$volume_name"
    }

    FORCE_DEPLOY_HAPROXY="{{ $force_deploy_haproxy }}"
    HOST="{{ $host }}"
    PREFIX="{{ $prefix }}"
    BACKEND_NAME="traefik_${PREFIX}"

    SITES_ROOT={{ $sites_root }}
    REVERSE_PROXY_DIR="$SITES_ROOT/{{ $reverse_proxy_folder_name }}"

    NETWORK_NAME="reverse_proxy"
    DESIRED_SUBNET="172.30.0.0/24"


    set -e
    if [ ! -d "$REVERSE_PROXY_DIR" ] || [ ! -f "$REVERSE_PROXY_DIR/docker-compose.yml" ]; then
        echo "Error: reverse-proxy directory is not ready: $REVERSE_PROXY_DIR"
        exit 1
    fi

    cd "$REVERSE_PROXY_DIR"
    HAPROXY_WORKING_DIR="$REVERSE_PROXY_DIR"
    TIMESTAMP="$(date +'%Y-%m-%d %H:%M:%S')"

    echo "[$TIMESTAMP] Starting HAProxy deployment for $HOST -> $BACKEND_NAME"
    echo "[$TIMESTAMP] Mode: Multi-site"

    # Cleanup test container
    echo "[$TIMESTAMP] Cleaning up any existing haproxy-test container..."
    if docker ps -q -f name=haproxy-test | grep -q . ; then
        docker stop haproxy-test
    fi
    if docker ps -a -q -f name=haproxy-test | grep -q . ; then
        docker rm haproxy-test
    fi

    if [ "$FORCE_DEPLOY_HAPROXY" != "true" ]; then
        echo "[$TIMESTAMP] FORCE_DEPLOY_HAPROXY not set to true, checking if map update needed..."
    fi

    # Get volume names
    CONFIG_VOLUME_NAME=$(get_real_compose_volume "haproxy-config")
    echo "[$TIMESTAMP] Config volume: $CONFIG_VOLUME_NAME"

    CERTS_VOLUME_NAME=$(get_real_compose_volume "haproxy-certs")
    echo "[$TIMESTAMP] Certs volume: $CERTS_VOLUME_NAME"

    # Verify volumes exist
    if ! docker volume inspect "$CONFIG_VOLUME_NAME" >/dev/null 2>&1; then
        echo "[$TIMESTAMP] Error: haproxy-config volume not found"
        exit 1
    fi

    if ! docker volume inspect "$CERTS_VOLUME_NAME" >/dev/null 2>&1; then
        echo "[$TIMESTAMP] Error: haproxy-certs volume not found"
        exit 1
    fi

    # Create temporary directory for working with configs
    TEMP_DIR=$(mktemp -d)
    MAP_DIR="$TEMP_DIR/maps"
    mkdir -p "$MAP_DIR"
    trap "rm -rf $TEMP_DIR" EXIT

    echo "[$TIMESTAMP] TEMP_DIR: $TEMP_DIR"
    echo "Running as user: $(whoami) (UID: $(id -u), GID: $(id -g))"

    # Check if config volume is initialized
    echo "[$TIMESTAMP] Checking if config volume is initialized..."
    VOLUME_HAS_CONFIG=$(docker run --rm -v "$CONFIG_VOLUME_NAME":/data alpine test -f /data/haproxy.cfg && echo "yes" || echo "no")

    if [ "$VOLUME_HAS_CONFIG" = "no" ]; then
        echo "[$TIMESTAMP] Config volume is empty, initializing with base configuration..."

        # Create base haproxy.cfg in temp dir
        cat > "$TEMP_DIR/haproxy.cfg.base" << 'HAPROXY_EOF'
    global
        daemon
        maxconn 8192
        log stdout local0
        stats socket /var/run/haproxy/haproxy.sock mode 660 level admin
        stats timeout 30s

    defaults
        mode http
        timeout connect 5000ms
        timeout client 50000ms
        timeout server 50000ms
        option httplog
        option dontlognull
        option forwardfor except 127.0.0.1
        option http-server-close
        option log-health-checks
        log global
        compression algo gzip
        compression type text/html text/plain text/css application/javascript text/javascript application/json text/xml application/xml

    frontend http_frontend
        bind *:80
        option httplog
        option forwardfor except 127.0.0.1
        redirect scheme https code 301

    frontend https_frontend
        bind *:443 ssl crt /certs/ alpn h2,http/1.1
        option httplog
        option forwardfor except 127.0.0.1

        acl blocked_ips_cf hdr(CF-Connecting-IP) -f /data/blocked_ips.txt
        acl blocked_ips_xff hdr(X-Forwarded-For) -f /data/blocked_ips.txt
        acl blocked_ips_src src -f /data/blocked_ips.txt
        http-request deny if blocked_ips_cf or blocked_ips_xff or blocked_ips_src

        # If host is not present in the map, fall back to `no_backend` which returns 403
        use_backend %[req.hdr(host),lower,map(/data/traefik_backends.map,no_backend)]
        default_backend default_backend

    backend default_backend
        mode http
        balance roundrobin
        option tcp-check
        server traefik traefik:8443 check ssl verify none inter 5s fall 3 rise 2
        http-request set-header X-Forwarded-Proto https
        http-request set-header X-Forwarded-Port 443
        http-request set-header X-Real-IP %[req.hdr(CF-Connecting-IP)] if { req.hdr(CF-Connecting-IP) -m found }
        http-request set-header X-Real-IP %[req.hdr(X-Forwarded-For)] if !{ req.hdr(CF-Connecting-IP) -m found } { req.hdr(X-Forwarded-For) -m found }
        http-request set-header X-Real-IP %[src] if !{ req.hdr(CF-Connecting-IP) -m found } !{ req.hdr(X-Forwarded-For) -m found }
        http-response set-header Strict-Transport-Security "max-age=31536000"

    # Fallback for hosts without a backend mapping
    backend no_backend
        mode http
        # Return a 403 Forbidden response for unmapped hosts
        http-request return status 403 content-type "text/plain" lf-string "Forbidden\n"

    # BACKENDS_START
    # BACKENDS_END
    HAPROXY_EOF

        # Initialize volume with base files
        docker run --rm \
            -v "$CONFIG_VOLUME_NAME":/data \
            -v "$TEMP_DIR":/tmp/work \
            alpine sh -c '
                cp /tmp/work/haproxy.cfg.base /data/haproxy.cfg
                touch /data/blocked_ips.txt
                echo "# Initial domains map" > /data/traefik_backends.map
                mkdir -p /data/maps
                chmod 644 /data/haproxy.cfg /data/traefik_backends.map /data/blocked_ips.txt
                chown -R 99:99 /data
                echo "Volume initialized"
            '

        echo "[$TIMESTAMP] Config volume initialized with base configuration"
    fi

    # Extract current files from HAProxy config volume
    echo "[$TIMESTAMP] Extracting current configuration files..."
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        -v "$CONFIG_VOLUME_NAME":/data:ro \
        -v "$TEMP_DIR":/tmp/work \
        alpine sh -c '
            # Copy maps directory
            if [ -d /data/maps ]; then
                cp -r /data/maps /tmp/work/
            else
                mkdir -p /tmp/work/maps
            fi

            # Copy old domain map if exists
            if [ -f /data/traefik_backends.map ]; then
                cp /data/traefik_backends.map /tmp/work/traefik_backends.map.old
            fi

            # Copy haproxy config
            if [ -f /data/haproxy.cfg ]; then
                cp /data/haproxy.cfg /tmp/work/haproxy.cfg.old
            fi

            # Copy blocked IPs for validation
            if [ -f /data/blocked_ips.txt ]; then
                cp /data/blocked_ips.txt /tmp/work/blocked_ips.txt
            else
                touch /tmp/work/blocked_ips.txt
            fi

            # Set readable permissions
            chmod -R 644 /tmp/work/* 2>/dev/null || true
            chmod 755 /tmp/work/maps 2>/dev/null || true
        '

    echo "[$TIMESTAMP] Extracted files:"
    ls -la "$TEMP_DIR"

    # 4. SITE DISCOVERY & MAP GENERATION
    echo "[$TIMESTAMP] Discovering sites for map file..."

    # Clear temporary map directory
    rm -rf "$TEMP_DIR/maps"
    mkdir -p "$TEMP_DIR/maps"

    extract_hosts() {
        JSON=$(docker compose config --format json)

        if [ -z "$JSON" ]; then
            echo "Error: Docker Compose config returned empty result." >&2
            return 1
        fi

        echo "$JSON" | jq -r '
            .services[]?
            | select(.labels?)
            | .labels
            | to_entries[]?
            | select(.key | contains(".rule"))
            | .value
            | match("Host\\(`([^`]+)`\\)"; "g")
            | .captures[0].string
        ' | sort -u
    }

    # Function to find Traefik container name
    find_traefik_container() {
        echo "[$TIMESTAMP] Locating Traefik container..." >&2
        local json
        if ! json=$(docker compose config --format json 2>/dev/null); then
            echo "[$TIMESTAMP] Error: docker compose config failed in $(pwd)" >&2
            return 1
        fi

        # Strategy 1: network alias
        local alias
        alias=$(echo "$json" | jq -r '
            .services | to_entries[]
            | select(
                (.key == "traefik" or (.key | test("traefik"; "i")))
                or (.value.image? // "" | test("traefik"; "i"))
                or (.value.labels?["traefik.enable"]? == "true")
            )
            | select(
                .value.networks?["reverse-proxy"]?.aliases? != null
                or .value.networks?.reverse_proxy?.aliases? != null
            )
            | .value.networks["reverse-proxy"]?.aliases[0]
            // .value.networks.reverse_proxy?.aliases[0]
        ' | head -1)

        if [ -n "$alias" ] && [ "$alias" != "null" ]; then
            echo "[$TIMESTAMP] Strategy 1: found via network alias: $alias" >&2
            echo "$alias"
            return 0
        fi
        echo "[$TIMESTAMP] Strategy 1 failed: no network alias found" >&2

        # Strategy 2: container_name
        local container_name
        container_name=$(echo "$json" | jq -r '
            .services | to_entries[]
            | select(
                (.key == "traefik" or (.key | test("traefik"; "i")))
                or (.value.image? // "" | test("traefik"; "i"))
            )
            | .value.container_name? // empty
        ' | head -1)

        if [ -n "$container_name" ] && [ "$container_name" != "null" ]; then
            echo "[$TIMESTAMP] Strategy 2: found via container_name: $container_name" >&2
            echo "$container_name"
            return 0
        fi
        echo "[$TIMESTAMP] Strategy 2 failed: no container_name found" >&2

        # Strategy 3: running container via docker compose ps
        local running_name
        running_name=$(docker compose ps 2>/dev/null \
            | awk 'NR>1 && /traefik/ {print $1}' \
            | head -1)

        if [ -n "$running_name" ] && [ "$running_name" != "null" ]; then
            echo "[$TIMESTAMP] Strategy 3: found via running containers: $running_name" >&2
            echo "$running_name"
            return 0
        fi
        echo "[$TIMESTAMP] Strategy 3 failed: no running traefik container found" >&2

        echo "[$TIMESTAMP] Error: Traefik container not found in $(pwd)" >&2
        return 1
    }

    # Check if container is connected to network, connect if not
    ensure_container_network() {
        local container="$1"
        local network="$2"

        # Resolve real container name from hostname/alias
        local real_name
        real_name=$(docker ps --format "@{{.Names}}" | while read -r name; do
            aliases=$(docker inspect "$name" \
                --format='@{{range $k, $v := .NetworkSettings.Networks}}@{{range $v.Aliases}}@{{.}} @{{end}}@{{end}}' 2>/dev/null)
            if echo "$aliases" | grep -qw "$container"; then
                echo "$name"
                break
            fi
        done)

        # Fallback - maybe it's already a real name
        if [ -z "$real_name" ]; then
            if docker inspect "$container" >/dev/null 2>&1; then
                real_name="$container"
            else
                echo "[$TIMESTAMP] Error: Cannot resolve container '$container'" >&2
                return 1
            fi
        fi

        echo "[$TIMESTAMP] Resolved '$container' -> real container: $real_name" >&2

        if docker inspect "$real_name" \
            --format='@{{range $k, $v := .NetworkSettings.Networks}}@{{$k}} @{{end}}' \
            | grep -qw "$network"; then
            echo "[$TIMESTAMP] $real_name is already connected to $network"
        else
            echo "[$TIMESTAMP] $real_name is not connected to $network, connecting..."
            docker network connect "$network" "$real_name" || {
                echo "[$TIMESTAMP] Error: Failed to connect $real_name to $network" >&2
                return 1
            }
            echo "[$TIMESTAMP] $real_name successfully connected to $network"
        fi
    }
    # Function to process a single site
    process_site() {
        local site_dir="$1"
        local site_name="$2"
        local container_name="$3"

        echo "[$TIMESTAMP] Processing site: $site_name"
        cd "$site_dir"

        local hosts=$(extract_hosts)

        if [ -z "$hosts" ] || [ -z "$container_name" ]; then
            echo "  -> Warning: Skipping $site_name"
            [ -z "$hosts" ] && echo "     Missing: Traefik hosts"
            [ -z "$container_name" ] && echo "     Missing: Traefik container"
            return 1
        fi

        local map_file="$TEMP_DIR/maps/${site_name}.map"

        cat > "$map_file" <<EOF
        # Map file for $site_name
        # Generated: $(date)
        # Container: $container_name
        EOF

        echo "$hosts" | while read -r host; do
            [ -n "$host" ] || continue
            echo "${host} ${container_name}" >> "$map_file"
            echo "  -> Found: $host -> $container_name"
        done

        echo "[$TIMESTAMP] Created map file: $map_file"
    }



    # Determine sites to process
    echo "[$TIMESTAMP] Multi-site mode: scanning $SITES_ROOT"
    EXPECTED_SITES=0
    PROCESSED_SITES=0
    SKIPPED_SITES=0
    SKIPPED_SITE_LIST=""
    SKIP_DETAILS_FILE="$TEMP_DIR/skipped-sites.log"
    : > "$SKIP_DETAILS_FILE"

    log_skip_reason() {
        local site="$1"
        local reason="$2"
        local details="${3:-}"
        SKIPPED_SITES=$((SKIPPED_SITES + 1))
        SKIPPED_SITE_LIST="$SKIPPED_SITE_LIST $site"
        echo "[$TIMESTAMP] Skip reason for $site: $reason${details:+ ($details)}"
        echo "$site|$reason|$details" >> "$SKIP_DETAILS_FILE"
    }

    for d in "$SITES_ROOT"/*/; do
        [ -f "$d/docker-compose.yml" ] || continue

        SITE_DIR=$(realpath "$d")
        [ "$SITE_DIR" = "$(realpath "$REVERSE_PROXY_DIR")" ] && continue

        SITE_NAME=$(basename "$SITE_DIR")

        # Skip backup and removed directories
        case "$SITE_NAME" in
            *.backup|*.removed.*) continue ;;
        esac

        EXPECTED_SITES=$((EXPECTED_SITES + 1))

        echo "[$TIMESTAMP] finding traefik container for $SITE_NAME in $SITE_DIR"
        cd "$SITE_DIR"

        TRAEFIK_CONTAINER=$(find_traefik_container)
        if [ -z "$TRAEFIK_CONTAINER" ]; then
            log_skip_reason "$SITE_NAME" "container_not_found" "$SITE_DIR"
            continue
        fi

        echo "[$TIMESTAMP] Found Traefik container: $TRAEFIK_CONTAINER for site $SITE_NAME"

        echo "[$TIMESTAMP] Ensuring $TRAEFIK_CONTAINER is connected to $NETWORK_NAME..."

        if ! ensure_container_network "$TRAEFIK_CONTAINER" "$NETWORK_NAME"; then
            log_skip_reason "$SITE_NAME" "network_connect_failed" "$TRAEFIK_CONTAINER -> $NETWORK_NAME"
            continue
        fi

        echo "[$TIMESTAMP] Processing site $SITE_NAME with Traefik container $TRAEFIK_CONTAINER..."

        if process_site "$SITE_DIR" "$SITE_NAME" "$TRAEFIK_CONTAINER"; then
            PROCESSED_SITES=$((PROCESSED_SITES + 1))
        else
            log_skip_reason "$SITE_NAME" "hosts_extraction_failed" "no valid Host() labels found"
        fi
    done

    echo "[$TIMESTAMP] Site discovery summary: expected=$EXPECTED_SITES processed=$PROCESSED_SITES skipped=$SKIPPED_SITES"
    if [ "$SKIPPED_SITES" -gt 0 ]; then
        echo "[$TIMESTAMP] Skip details:"
        while IFS='|' read -r site reason details; do
            [ -n "$site" ] || continue
            echo "[$TIMESTAMP] - $site: $reason${details:+ ($details)}"
        done < "$SKIP_DETAILS_FILE"
        echo "[$TIMESTAMP] Error: refusing to apply partial HAProxy map. Skipped sites:$SKIPPED_SITE_LIST"
        exit 1
    fi

    rm -f "$SKIP_DETAILS_FILE"

    cd "$HAPROXY_WORKING_DIR"


    # 5. MERGE ALL MAP FILES
    echo "[$TIMESTAMP] Merging all map files..."

    cat > "$TEMP_DIR/traefik_backends.map.new" <<EOF
    # Auto-generated merged map file - DO NOT EDIT MANUALLY
    # Last updated: $(date)
    # Source: temporary maps/*.map
    EOF

    MAP_COUNT=0
    if [ -d "$TEMP_DIR/maps" ]; then
        for map_file in "$TEMP_DIR/maps"/*.map; do
            if [ -f "$map_file" ]; then
                echo "" >> "$TEMP_DIR/traefik_backends.map.new"
                echo "# ===== From: $(basename "$map_file") =====" >> "$TEMP_DIR/traefik_backends.map.new"
                cat "$map_file" >> "$TEMP_DIR/traefik_backends.map.new"
                MAP_COUNT=$((MAP_COUNT + 1))
            fi
        done
    fi

    echo "[$TIMESTAMP] Merged $MAP_COUNT map file(s)"
    echo "[$TIMESTAMP] Merged traefik_backends.map preview:"
    head -20 "$TEMP_DIR/traefik_backends.map.new"

    # Preserve existing host mappings if site discovery missed some projects.
    # This prevents unrelated domains from returning 404 after another project's deploy.
    if [ -f "$TEMP_DIR/traefik_backends.map.old" ]; then
        awk '!/^#/ && NF>=2 {print $1 " " $2}' "$TEMP_DIR/traefik_backends.map.new" | sort -u > "$TEMP_DIR/.new_hosts.tmp"
        awk '!/^#/ && NF>=2 {print $1 " " $2}' "$TEMP_DIR/traefik_backends.map.old" | sort -u > "$TEMP_DIR/.old_hosts.tmp"

        while read -r old_host old_backend; do
            [ -n "$old_host" ] || continue
            if ! awk '{print $1}' "$TEMP_DIR/.new_hosts.tmp" | grep -Fxq "$old_host"; then
                echo "$old_host $old_backend" >> "$TEMP_DIR/traefik_backends.map.new"
                echo "$old_host $old_backend" >> "$TEMP_DIR/.new_hosts.tmp"
                echo "[$TIMESTAMP] Preserved previous mapping: $old_host -> $old_backend"
            fi
        done < "$TEMP_DIR/.old_hosts.tmp"

        rm -f "$TEMP_DIR/.new_hosts.tmp" "$TEMP_DIR/.old_hosts.tmp"
    fi

    if [ "$MAP_COUNT" -eq 0 ]; then
        echo "[$TIMESTAMP] ERROR: No map files generated!"
        exit 1
    fi

    echo "[$TIMESTAMP] Merged traefik_backends.map content:"
    cat "$TEMP_DIR/traefik_backends.map.new"



    # Check if map file changed
    MAP_CHANGED=false
    if [ -f "$TEMP_DIR/traefik_backends.map.old" ]; then
        if ! diff -q "$TEMP_DIR/traefik_backends.map.old" "$TEMP_DIR/traefik_backends.map.new" >/dev/null 2>&1; then
            MAP_CHANGED=true
            echo "[$TIMESTAMP] Map file has changed"
        else
            echo "[$TIMESTAMP] Map file unchanged"
        fi
    else
        MAP_CHANGED=true
        echo "[$TIMESTAMP] No previous map file, will create new one"
    fi

    # Check if backend exists in haproxy.cfg
    #CONFIG_CHANGED=false
    #if [ -f "$TEMP_DIR/haproxy.cfg.old" ]; then
    #    echo "[$TIMESTAMP] Checking if backend exists in haproxy.cfg..."
    #    if ! grep -q "^backend ${BACKEND_NAME}" "$TEMP_DIR/haproxy.cfg.old"; then
    #        echo "[$TIMESTAMP] Backend $BACKEND_NAME not found in config, regeneration needed"
    #        CONFIG_CHANGED=true
    #    else
    #        echo "[$TIMESTAMP] Backend $BACKEND_NAME exists in config"
    #    fi
    #else
    #    echo "[$TIMESTAMP] No existing haproxy.cfg found, will create new one"
    #    CONFIG_CHANGED=true
    #fi

    # If nothing changed and not forcing, exit
    if [ "$MAP_CHANGED" = "false" ] && [ "$FORCE_DEPLOY_HAPROXY" != "true" ]; then
        echo "[$TIMESTAMP] No changes detected and force deploy not requested, exiting"
        exit 0
    fi




    # Generate new haproxy.cfg with all backends
    #if [ "$CONFIG_CHANGED" = "true" ]; then
        echo "[$TIMESTAMP] Regenerating backends in haproxy.cfg..."

        # Extract unique backend names from merged map
        UNIQUE_BACKENDS=$(grep -v "^#" "$TEMP_DIR/traefik_backends.map.new" | grep -v "^$" | awk '{print $2}' | sort -u)

        # Generate backends section
        BACKENDS_SECTION=""
        for backend_name in $UNIQUE_BACKENDS; do
            project=$(echo "$backend_name" | sed 's/^traefik-//')
            server_host="${backend_name}:8443"

            BACKENDS_SECTION="${BACKENDS_SECTION}
    backend ${backend_name}
        mode http
        balance roundrobin

        option tcp-check

        server ${project} ${server_host} check ssl verify none inter 5s fall 3 rise 2

        http-request set-header X-Forwarded-Proto https
        http-request set-header X-Forwarded-Port 443
        http-request set-header X-Real-IP %[req.hdr(CF-Connecting-IP)] if { req.hdr(CF-Connecting-IP) -m found }
        http-request set-header X-Real-IP %[req.hdr(X-Forwarded-For)] if !{ req.hdr(CF-Connecting-IP) -m found } { req.hdr(X-Forwarded-For) -m found }
        http-request set-header X-Real-IP %[src] if !{ req.hdr(CF-Connecting-IP) -m found } !{ req.hdr(X-Forwarded-For) -m found }
        http-response set-header Strict-Transport-Security \"max-age=31536000\"

    "
        done

        # Replace backends section in config
        awk -v backends="$BACKENDS_SECTION" '
            /^# BACKENDS_START/ {
                print
                print backends
                skip=1
                next
            }
            /^# BACKENDS_END/ {
                skip=0
            }
            !skip { print }
        ' "$TEMP_DIR/haproxy.cfg.old" > "$TEMP_DIR/haproxy.cfg.new"

        echo "[$TIMESTAMP] Generated new haproxy.cfg"
    #else
    #    cp "$TEMP_DIR/haproxy.cfg.old" "$TEMP_DIR/haproxy.cfg.new"
    #fi


    # Set proper permissions for validation
    chmod 777 "$TEMP_DIR"
    chmod 644 "$TEMP_DIR/haproxy.cfg.new"
    chmod 644 "$TEMP_DIR/traefik_backends.map.new"
    chmod 644 "$TEMP_DIR/blocked_ips.txt"

    # Get HAProxy image from docker-compose
    echo "[$TIMESTAMP] Getting HAProxy image..."

    # Try to get image from running container
    CONTAINER_ID=$(docker compose -f "$HAPROXY_WORKING_DIR/docker-compose.yml" ps -q haproxy 2>/dev/null | head -n 1)
    if [ -n "$CONTAINER_ID" ]; then
        HAPROXY_IMAGE=$(docker inspect --format='@{{.Image}}' "$CONTAINER_ID")
        HAPROXY_IMAGE_REF=$(docker inspect --format='@{{.Config.Image}}' "$CONTAINER_ID")
        echo "[$TIMESTAMP] Found running container image id: $HAPROXY_IMAGE"
        echo "[$TIMESTAMP] Found running container image ref: $HAPROXY_IMAGE_REF"
    fi

    # If no running container, try to get from compose config
    if [ -z "$HAPROXY_IMAGE" ] || [ "$HAPROXY_IMAGE" = "null" ]; then
        echo "[$TIMESTAMP] No running container found, checking compose configuration..."
        HAPROXY_IMAGE=$(docker compose -f "$HAPROXY_WORKING_DIR/docker-compose.yml" config --format json 2>/dev/null | jq -r '.services.haproxy.image // empty')
    fi

    # If still not found, build the image
    if [ -z "$HAPROXY_IMAGE" ] || [ "$HAPROXY_IMAGE" = "null" ]; then
        echo "[$TIMESTAMP] No image specified, building from compose file..."
        docker compose -f "$HAPROXY_WORKING_DIR/docker-compose.yml" build haproxy || { echo "Error: Failed to build HAProxy image"; exit 1; }

        # Get the built image name
        HAPROXY_IMAGE=$(docker compose -f "$HAPROXY_WORKING_DIR/docker-compose.yml" config --format json 2>/dev/null | jq -r '.services.haproxy.image // empty')

        # If still not found, construct from project name
        if [ -z "$HAPROXY_IMAGE" ]; then
            PROJECT_NAME=$(docker compose -f "$HAPROXY_WORKING_DIR/docker-compose.yml" config --project-name 2>/dev/null || basename "$(pwd)")
            HAPROXY_IMAGE="${PROJECT_NAME}-haproxy:latest"
        fi
    fi

    echo "[$TIMESTAMP] Using image for validation: $HAPROXY_IMAGE"

    # Verify image exists
    if ! docker image inspect "$HAPROXY_IMAGE" >/dev/null 2>&1; then
        echo "[$TIMESTAMP] Error: Image $HAPROXY_IMAGE does not exist"
        echo "[$TIMESTAMP] Available images:"
        docker images | grep haproxy
        exit 1
    fi

    # Debug: show what files we're validating with
    echo "[$TIMESTAMP] Files available for validation:"
    ls -lah "$TEMP_DIR"

    # Validate new haproxy config using pure docker (no port conflicts)
    echo "[$TIMESTAMP] Validating new configuration..."
    set +e  # Temporarily disable exit on error to capture output

    VALIDATION_OUTPUT=$(docker run --rm \
        --entrypoint="" \
        --network "$NETWORK_NAME" \
        -v "$TEMP_DIR":/data \
        -v "$CERTS_VOLUME_NAME":/certs:ro \
        "$HAPROXY_IMAGE" sh -c '
            set -e

            # Copy files to expected names for validation
            cp /data/haproxy.cfg.new /data/haproxy.cfg.validate
            cp /data/traefik_backends.map.new /data/traefik_backends.map

            # Run validation
            haproxy -c -f /data/haproxy.cfg.validate

            # Clean up
            rm -f /data/haproxy.cfg.validate
        ' 2>&1)

    VALIDATION_EXIT_CODE=$?
    set -e  # Re-enable exit on error

    if [ $VALIDATION_EXIT_CODE -ne 0 ]; then
        echo "[$TIMESTAMP] Error: Generated configuration is invalid"
        echo "[$TIMESTAMP] Validation output:"
        echo "$VALIDATION_OUTPUT"
        echo "[$TIMESTAMP] Aborting deployment - no changes will be applied"
        exit 1
    fi

    echo "[$TIMESTAMP] Configuration validated successfully"
    echo "$VALIDATION_OUTPUT"

    # Only if validation passed - update volumes with new configs
    echo "[$TIMESTAMP] Updating configuration in volume..."
    docker run --rm \
        -v "$CONFIG_VOLUME_NAME":/data \
        -v "$TEMP_DIR":/tmp/work \
        alpine sh -c '
            # Backup old configs
            if [ -f /data/haproxy.cfg ]; then
                cp /data/haproxy.cfg /data/haproxy.cfg.backup.$(date +%Y%m%d_%H%M%S)
            fi
            if [ -f /data/traefik_backends.map ]; then
                cp /data/traefik_backends.map /data/traefik_backends.map.backup.$(date +%Y%m%d_%H%M%S)
            fi

            # Copy new configs
            cp /tmp/work/haproxy.cfg.new /data/haproxy.cfg
            cp /tmp/work/haproxy.cfg.new /data/haproxy111.cfg
            cp /tmp/work/traefik_backends.map.new /data/traefik_backends.map

            # Update maps directory
            mkdir -p /data/maps
            cp -r /tmp/work/maps/* /data/maps/ 2>/dev/null || true

            # Ensure blocked_ips.txt exists
            if [ ! -f /data/blocked_ips.txt ]; then
                touch /data/blocked_ips.txt
            fi

            # Set permissions
            chmod 644 /data/haproxy.cfg /data/traefik_backends.map /data/blocked_ips.txt
            find /data/maps -type f -name "*.map" -exec chmod 644 {} \; 2>/dev/null || true

            # Set ownership for HAProxy user (uid/gid 99)
            chown 99:99 /data/haproxy.cfg /data/traefik_backends.map /data/blocked_ips.txt
            chown -R 99:99 /data/maps 2>/dev/null || true

            echo "Configuration files updated in volume"
        '

    echo "[$TIMESTAMP] Configuration updated in volume"

    ACTUAL_CONTAINER_ID=$(docker compose -f "$HAPROXY_WORKING_DIR/docker-compose.yml" ps -q haproxy 2>/dev/null | head -n 1)
    ACTUAL_NAME=""
    if [ -n "$ACTUAL_CONTAINER_ID" ]; then
        ACTUAL_NAME=$(docker inspect --format '@{{.Name}}' "$ACTUAL_CONTAINER_ID" 2>/dev/null | sed 's#^/##')
    fi

    if [ -z "$ACTUAL_NAME" ]; then
        echo "[$TIMESTAMP] No running HAProxy container found, starting one..."
        docker compose -f "$HAPROXY_WORKING_DIR/docker-compose.yml" build haproxy || { echo "Error: Failed to build HAProxy image"; exit 1; }
        docker compose -f "$HAPROXY_WORKING_DIR/docker-compose.yml" up -d haproxy || { echo "Error: Failed to start HAProxy container"; exit 1; }

        ACTUAL_CONTAINER_ID=$(docker compose -f "$HAPROXY_WORKING_DIR/docker-compose.yml" ps -q haproxy 2>/dev/null | head -n 1)
        if [ -n "$ACTUAL_CONTAINER_ID" ]; then
            ACTUAL_NAME=$(docker inspect --format '@{{.Name}}' "$ACTUAL_CONTAINER_ID" 2>/dev/null | sed 's#^/##')
        fi
        if [ -z "$ACTUAL_NAME" ]; then
            echo "[$TIMESTAMP] Error: Cannot find running HAProxy container after startup"
            docker compose -f "$HAPROXY_WORKING_DIR/docker-compose.yml" logs haproxy
            exit 1
        fi

        echo "[$TIMESTAMP] Waiting for production container to become healthy..."
        for i in {1..30}; do
            STATUS=$(docker inspect --format '@{{.State.Health.Status}}' "$ACTUAL_NAME" 2>/dev/null || echo "unknown")
            if [ "$STATUS" = "healthy" ]; then
                echo "[$TIMESTAMP] Production container healthy"
                break
            fi
            if [ $i -eq 30 ]; then
                echo "[$TIMESTAMP] Error: Production container failed to become healthy"
                docker logs "$ACTUAL_NAME" --tail=20
                exit 1
            fi
            [ $((i % 5)) -eq 0 ] && echo "[$TIMESTAMP] Still waiting... ($i/30)"
            sleep 1
        done

        if ! docker port "$ACTUAL_NAME" | grep -q "80\|443"; then
            echo "[$TIMESTAMP] Error: $ACTUAL_NAME not bound to production ports"
            docker port "$ACTUAL_NAME"
            exit 1
        fi

        echo "[$TIMESTAMP] HAProxy deployment completed"
    else
        if [ "$FORCE_DEPLOY_HAPROXY" = "true" ]; then
            echo "[$TIMESTAMP] FORCE_DEPLOY_HAPROXY requested; building fresh HAProxy image without restarting the active container to preserve zero-downtime"
            docker compose -f "$HAPROXY_WORKING_DIR/docker-compose.yml" build --no-cache haproxy || { echo "Error: Failed to build HAProxy image"; exit 1; }
            echo "[$TIMESTAMP] New image built but not activated automatically; a manual restart is still required if you need to roll out image-level changes"
        fi

        echo "[$TIMESTAMP] Applying updated configuration via explicit HAProxy reload..."

        docker exec "$ACTUAL_NAME" sh -lc '
            set -e
            test -f /data/haproxy.cfg
            test -f /var/run/haproxy/haproxy.pid
            PID=$(cat /var/run/haproxy/haproxy.pid)
            haproxy -f /data/haproxy.cfg -c
            haproxy -f /data/haproxy.cfg -p /var/run/haproxy/haproxy.pid -sf "$PID"
        ' || {
            echo "[$TIMESTAMP] Error: explicit HAProxy reload failed"
            docker logs "$ACTUAL_NAME" --tail=40
            exit 1
        }

        echo "[$TIMESTAMP] Waiting for HAProxy to report healthy after reload..."
        for i in {1..20}; do
            STATUS=$(docker inspect --format '@{{.State.Health.Status}}' "$ACTUAL_NAME" 2>/dev/null || echo "unknown")
            if [ "$STATUS" = "healthy" ]; then
                break
            fi
            sleep 1
        done

        STATUS=$(docker inspect --format '@{{.State.Health.Status}}' "$ACTUAL_NAME" 2>/dev/null || echo "unknown")
        if [ "$STATUS" = "healthy" ]; then
            echo "[$TIMESTAMP] HAProxy is healthy after reload"
        else
            echo "[$TIMESTAMP] Warning: HAProxy status is $STATUS"
            docker logs "$ACTUAL_NAME" --tail=20
        fi

        echo "[$TIMESTAMP] Map update completed"
    fi

    echo "[$TIMESTAMP] Deployment finished successfully"

    # Report actual mapping state to avoid misleading messages after removals
    MAPPED=false
    if [ -n "$CONFIG_VOLUME_NAME" ]; then
        if docker run --rm -v "$CONFIG_VOLUME_NAME":/data:ro alpine sh -c "awk '!/^#/ && NF>=2 {print \$1 \" \" \$2}' /data/traefik_backends.map | grep -F -x '${HOST} ${BACKEND_NAME}' >/dev/null 2>&1"; then
            MAPPED=true
        fi
    fi

    if [ "$MAPPED" = true ]; then
        echo "[$TIMESTAMP] Host $HOST is now mapped to $BACKEND_NAME"
    else
        echo "[$TIMESTAMP] Host $HOST is not mapped to any backend"
    fi
@endtask

@task('finalize_deploy', ['on' => 'web_new'])
    set -e
    cd {{ $remote_html_path }}

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Starting all containers in detached mode...\033[0m"
    docker compose up -d || { echo "Error: Failed to start containers"; exit 1; }
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] All containers started successfully\033[0m"

    if [ -z "$DEPLOYMENT_COLOR" ]; then
        if docker ps --format "table @{{.Names}}" | grep -q "^{{ $container_prefix }}app-blue"; then
            APP_CONTAINER_NAME="{{ $container_prefix }}app-blue"
        elif docker ps --format "table @{{.Names}}" | grep -q "^{{ $container_prefix }}app-green"; then
            APP_CONTAINER_NAME="{{ $container_prefix }}app-green"
        else
            echo "Error: Could not determine the active app container color. DEPLOYMENT_COLOR is not set and no blue/green app container found." >&2
            exit 1
        fi
    else
        APP_CONTAINER_NAME="{{ $container_prefix }}app-$DEPLOYMENT_COLOR"
    fi

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Targeting app container: $APP_CONTAINER_NAME\033[0m"

    # Execute a command as www-data while hydrating APP_KEY/DB_PASSWORD from Docker secrets.
    run_as_www_data_with_secrets() {
        container="$1"
        command="$2"

        docker exec --user root \
            -e RUN_AS_WWW_DATA_COMMAND="$command" \
            "$container" \
            bash -lc '
                set -e

                if [ -n "${APP_KEY:-}" ] && [ -f "${APP_KEY}" ]; then
                    export APP_KEY="$(cat "${APP_KEY}")"
                elif [ -f /run/secrets/app_key ]; then
                    export APP_KEY="$(cat /run/secrets/app_key)"
                fi

                if [ -n "${DB_PASSWORD:-}" ] && [ -f "${DB_PASSWORD}" ]; then
                    export DB_PASSWORD="$(cat "${DB_PASSWORD}")"
                elif [ -f /run/secrets/db_password ]; then
                    export DB_PASSWORD="$(cat /run/secrets/db_password)"
                fi

                exec gosu www-data bash -lc "$RUN_AS_WWW_DATA_COMMAND"
            '
    }

    # Helper: run an artisan command inside a container only if it exists
    run_artisan_if_exists() {
        container="$1"
        cmd="$2"
        allow_failure="${3:-false}"

        if run_as_www_data_with_secrets "$container" "cd /var/www/html && php artisan help $cmd >/dev/null 2>&1"; then
            if [ "$allow_failure" = "true" ]; then
                run_as_www_data_with_secrets "$container" "cd /var/www/html && php artisan $cmd" </dev/null || echo -e "\033[0;33m[WARNING] artisan $cmd failed in $container (non-fatal)\033[0m"
            else
                run_as_www_data_with_secrets "$container" "cd /var/www/html && php artisan $cmd" </dev/null || { echo -e "\033[0;31m[ERROR] artisan $cmd failed in $container\033[0m"; exit 1; }
            fi
        else
            echo -e "\033[0;33m[INFO] Artisan command '$cmd' not available in $container; skipping.\033[0m"
        fi
    }

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Running migrations...\033[0m"
    run_as_www_data_with_secrets "$APP_CONTAINER_NAME" "cd /var/www/html && php artisan migrate --force" </dev/null || { echo "Error: Failed to run migrations on $APP_CONTAINER_NAME"; exit 1; }

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Updating Composer dependencies...\033[0m"
    run_as_www_data_with_secrets "$APP_CONTAINER_NAME" "cd /var/www/html && composer install --no-dev --optimize-autoloader" </dev/null || { echo "Error: Failed to update Composer dependencies on $APP_CONTAINER_NAME"; exit 1; }
    echo "Composer completed"

    # -----------------------------------------
    # FILAMENT OPTIMIZATION
    # -----------------------------------------
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Optimizing Filament assets...\033[0m"
    # Guard: only run Filament optimization if the artisan command exists
    if run_as_www_data_with_secrets "$APP_CONTAINER_NAME" "cd /var/www/html && php artisan help filament:optimize >/dev/null 2>&1"; then
        run_as_www_data_with_secrets "$APP_CONTAINER_NAME" "cd /var/www/html && php artisan filament:optimize" </dev/null || { echo "Error: Filament optimize command failed"; exit 1; }
    else
        echo -e "\033[0;33m[INFO] Filament commands not available in $APP_CONTAINER_NAME; skipping Filament optimization.\033[0m"
    fi
    # -----------------------------------------

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Clearing existing caches...\033[0m"

    echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Clearing config cache...\033[0m"
    run_as_www_data_with_secrets "$APP_CONTAINER_NAME" "cd /var/www/html && php artisan config:clear" </dev/null || { echo "Warning: Failed to clear config cache on $APP_CONTAINER_NAME"; }

    echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Clearing route cache...\033[0m"
    run_as_www_data_with_secrets "$APP_CONTAINER_NAME" "cd /var/www/html && php artisan route:clear" </dev/null || { echo "Warning: Failed to clear route cache on $APP_CONTAINER_NAME"; }

    echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Clearing view cache...\033[0m"
    run_as_www_data_with_secrets "$APP_CONTAINER_NAME" "cd /var/www/html && php artisan view:clear" </dev/null || { echo "Warning: Failed to clear view cache on $APP_CONTAINER_NAME"; }

    echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Clearing Sushi cache...\033[0m"
    run_artisan_if_exists "$APP_CONTAINER_NAME" "cache:clear-sushi" "true"

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Running post-deployment tasks...\033[0m"
    run_artisan_if_exists "$APP_CONTAINER_NAME" "app:post-deploy"

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Rebuilding caches...\033[0m"

    echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Caching config...\033[0m"
    run_as_www_data_with_secrets "$APP_CONTAINER_NAME" "cd /var/www/html && php artisan config:cache" </dev/null || { echo "Error: Failed to cache config on $APP_CONTAINER_NAME"; exit 1; }

    echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Caching routes...\033[0m"
    run_as_www_data_with_secrets "$APP_CONTAINER_NAME" "cd /var/www/html && php artisan route:cache" </dev/null || { echo "Error: Failed to cache routes on $APP_CONTAINER_NAME"; exit 1; }

    echo -e "\033[0;33m[$(date +'%Y-%m-%d %H:%M:%S')] Caching views...\033[0m"
    run_as_www_data_with_secrets "$APP_CONTAINER_NAME" "cd /var/www/html && php artisan view:cache" </dev/null || { echo "Error: Failed to cache views on $APP_CONTAINER_NAME"; exit 1; }

    # Ensure Octane is running; try restart or manual start if needed, abort if unable
    ensure_octane_running() {
        container="$1"
        user_opts="${2:---user www-data}"

        is_running() {
            docker exec $user_opts "$container" bash -lc '
                if command -v pgrep >/dev/null 2>&1; then
                    pgrep -f "artisan octane:start" >/dev/null 2>&1 && exit 0
                    pgrep -f "swoole" >/dev/null 2>&1 && exit 0
                fi

                if command -v ps >/dev/null 2>&1; then
                    ps -ef 2>/dev/null | grep -q "[a]rtisan octane:start" && exit 0
                    ps -ef 2>/dev/null | grep -q "swoole" && exit 0
                fi

                exit 1
            ' </dev/null >/dev/null 2>&1
        }

        # quick wait for existing process
        ATTEMPTS=0
        until is_running || [ $ATTEMPTS -ge 10 ]; do
            echo "Waiting for Octane to appear in $container... ($((ATTEMPTS+1))/10)"
            sleep 2
            ATTEMPTS=$((ATTEMPTS+1))
        done
        if is_running; then
            echo "Octane is running in $container"
            return 0
        fi

        echo "Octane not detected in $container; attempting to restart container to let its CMD start Octane..."
        docker restart "$container" >/dev/null 2>&1 || echo "Warning: docker restart failed for $container"

        ATTEMPTS=0
        until is_running || [ $ATTEMPTS -ge 10 ]; do
            echo "Waiting for Octane after container restart... ($((ATTEMPTS+1))/10)"
            sleep 2
            ATTEMPTS=$((ATTEMPTS+1))
        done
        if is_running; then
            echo "Octane started after container restart"
            return 0
        fi

        echo "Container restart didn't start Octane; attempting manual start inside container..."
        if docker exec $user_opts "$container" bash -c "cd /var/www/html && php artisan help octane:start >/dev/null 2>&1"; then
            docker exec $user_opts "$container" bash -lc "cd /var/www/html && nohup php artisan octane:start --server=swoole --host=0.0.0.0 --port=9501 --workers=\"\\\${OCTANE_WORKERS:-4}\" >/dev/null 2>&1 &" </dev/null || echo "Warning: manual octane:start failed"

            ATTEMPTS=0
            until is_running || [ $ATTEMPTS -ge 10 ]; do
                echo "Waiting for Octane after manual start... ($((ATTEMPTS+1))/10)"
                sleep 2
                ATTEMPTS=$((ATTEMPTS+1))
            done
            if is_running; then
                echo "Octane started manually inside $container"
                return 0
            fi
        else
            echo "octane:start command not available in $container; cannot start Octane manually"
        fi

        echo "Failed to ensure Octane is running in $container"
        return 1
    }

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Restarting Swoole server...\033[0m"
    if ! ensure_octane_running "$APP_CONTAINER_NAME" "--user www-data"; then
        echo -e "\033[0;31m[ERROR] Octane is not running and could not be started on $APP_CONTAINER_NAME; aborting deployment\033[0m"
        exit 1
    fi
    run_artisan_if_exists "$APP_CONTAINER_NAME" "octane:reload" "true"

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] All caching and Swoole restart completed successfully\033[0m"
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Deployment finalization completed successfully ✓\033[0m"
@endtask

@task('setup_backup', ['on' => 'local'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Setting up automated backup...\033[0m"

    scp -i {{ env('SSH_KEY_PATH') }} -P {{ env('SSH_PORT_NEW') }} -o StrictHostKeyChecking=no deploy/configs/backup.sh {{ 'deploy@' . env('SERVER_IP') }}:/home/deploy/backup-{{ $site_name }}.sh

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Backup script copied successfully ✓\033[0m"
@endtask

@task('configure_backup', ['on' => 'web_new'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Configuring backup cron job...\033[0m"

    chmod +x /home/deploy/backup-{{ $site_name }}.sh

    mkdir -p /home/deploy/backups

    crontab -l 2>/dev/null | grep -v '/home/deploy/backup-{{ $site_name }}.sh' | crontab - 2>/dev/null || true

    (crontab -l 2>/dev/null; echo '0 2 * * * /home/deploy/backup-{{ $site_name }}.sh >> /home/deploy/backups/backup-{{ $site_name }}.log 2>&1') | crontab -

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Backup cron job configured successfully ✓\033[0m"
@endtask

@task('health_checks', ['on' => 'web_new'])

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Performing health checks ...\033[0m"

    if curl -sSf --max-time 10 "https://{{ $site_name }}" >/dev/null 2>&1; then
        echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] ✅ Main site is accessible\033[0m"
    else
        echo -e "\033[1;33m[WARNING] ⚠️ Main site not yet accessible, may need more time\033[0m"
    fi

    if curl -sSfI --max-time 10 "https://{{ $site_name }}" >/dev/null 2>&1; then
        echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] ✅ SSL certificate working correctly\033[0m"
    else
        echo -e "\033[1;33m[WARNING] ⚠️ SSL certificate may still be provisioning\033[0m"
    fi

    if curl -sSf --max-time 10 "https://{{ $site_name }}/up" >/dev/null 2>&1; then
        echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] ✅ Application health check passed\033[0m"
    else
        echo -e "\033[1;33m[WARNING] ⚠️ Application health check failed, may need troubleshooting\033[0m"
    fi

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] 🎉 Health checks completed!\033[0m"
@endtask

@task('monitor_restarts', ['on' => 'web_new'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Checking container restart logs...\033[0m"

    cd {{ $remote_html_path }}

    # -------------------------------
    # Detect active app (blue-green)
    # -------------------------------
    ACTIVE_APP=""
    for svc in $(docker compose ps --services | grep -E 'app-(blue|green)' | grep '{{ $site_prefix }}'); do
        STATUS=$(docker inspect -f '@{{.State.Health.Status}}' $(docker compose ps -q "$svc"))
        if [ "$STATUS" = "healthy" ]; then
            ACTIVE_APP="$svc"
            break
        fi
    done

    if [ -z "$ACTIVE_APP" ]; then
        echo -e "\033[1;33m[WARNING] ⚠️ No healthy app container detected, check manually\033[0m"
        ACTIVE_APP=$(docker compose ps --services | grep -E 'app-(blue|green)' | grep '{{ $site_prefix }}' | head -n 1)
    fi

    RESTART_COUNT=$(docker inspect -f '@{{ .RestartCount }}' $(docker compose ps -q "$ACTIVE_APP"))
    echo -e "\033[0;34m[INFO] Active app container ($ACTIVE_APP) restarts: $RESTART_COUNT\033[0m"

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Recent $ACTIVE_APP logs:\033[0m"
    docker compose logs --tail=50 "$ACTIVE_APP"

    HEALTH_STATUS=$(docker inspect -f '@{{ .State.Health.Status }}' $(docker compose ps -q "$ACTIVE_APP"))
    echo -e "\033[0;34m[INFO] $ACTIVE_APP health: $HEALTH_STATUS\033[0m"

    if [ "$HEALTH_STATUS" != "healthy" ]; then
        echo -e "\033[1;33m[WARNING] ⚠️ $ACTIVE_APP is not healthy, investigate logs\033[0m"
    fi

    # -------------------------------
    # Queue monitoring (blue-green)
    # -------------------------------
    QUEUE_SERVICE=$(docker compose ps --services | grep -E 'queue-(blue|green)' | grep '{{ $site_prefix }}' | head -n 1)
    QUEUE_RESTART_COUNT=$(docker inspect -f '@{{ .RestartCount }}' $(docker compose ps -q "$QUEUE_SERVICE"))
    echo -e "\033[0;34m[INFO] Queue container ($QUEUE_SERVICE) restarts: $QUEUE_RESTART_COUNT\033[0m"

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Recent $QUEUE_SERVICE logs:\033[0m"
    docker compose logs --tail=50 "$QUEUE_SERVICE"

    QUEUE_HEALTH_STATUS=$(docker inspect -f '@{{ .State.Health.Status }}' $(docker compose ps -q "$QUEUE_SERVICE"))
    echo -e "\033[0;34m[INFO] $QUEUE_SERVICE health: $QUEUE_HEALTH_STATUS\033[0m"

    if [ "$QUEUE_HEALTH_STATUS" != "healthy" ]; then
        echo -e "\033[1;33m[WARNING] ⚠️ $QUEUE_SERVICE is not healthy, investigate logs\033[0m"
    fi
@endtask


@task('configure_sudoers', ['on' => 'web_changed'])
    #!/bin/bash
    SUDOERS_FILE="/etc/sudoers.d/deploy-temp"
    sudo tee "$SUDOERS_FILE" > /dev/null <<EOL
Defaults:deploy !requiretty
deploy ALL=(ALL) NOPASSWD: \
    /usr/bin/apt-get, \
    /usr/bin/apt, \
    /usr/bin/curl, \
    /usr/bin/install, \
    /usr/bin/touch, \
    /bin/touch, \
    /usr/bin/chmod, \
    /usr/bin/chown, \
    /usr/bin/tee, \
    /usr/sbin/usermod, \
    /bin/systemctl, \
    /usr/bin/docker, \
    /usr/bin/mkdir, \
    /usr/bin/cp, \
    /usr/bin/mv, \
    /usr/bin/rm, \
    /usr/sbin/logrotate, \
    /usr/bin/tar, \
    /usr/bin/rsync, \
    /usr/sbin/ufw
EOL
    sudo visudo -cf "$SUDOERS_FILE" || { echo "Sudoers syntax error"; exit 1; }
@endtask

@task('remove_sudoers', ['on' => 'web_changed'])
    SUDOERS_FILE="/etc/sudoers.d/deploy-temp"
    echo "Removing temporary sudoers file $SUDOERS_FILE to revoke sudo rights for deploy..."

    sudo rm -f "$SUDOERS_FILE"

    echo "Sudoers file removed."
@endtask

@task('docker-cleanup', ['on' => 'web_new'])
    set -euo pipefail

    CLEAN_SCOPE="{{ $docker_cleanup_scope }}"
    CLEAN_TARGETS_RAW="{{ $docker_cleanup_targets }}"
    CLEAN_PROJECTS_RAW="{{ $docker_cleanup_projects }}"
    CLEAN_IMAGES_MODE="{{ $docker_cleanup_images_mode }}"
    SITES_ROOT="{{ $sites_root }}"
    REVERSE_PROXY_DIR="$SITES_ROOT/{{ $reverse_proxy_folder_name }}"
    CURRENT_SITE="{{ $site_name }}"

    normalize_csv() {
        echo "$1" \
            | tr '[:upper:]' '[:lower:]' \
            | tr '; ' ',,' \
            | sed 's/,,\+/,/g; s/^,//; s/,$//'
    }

    trim_value() {
        echo "$1" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//'
    }

    discover_projects() {
        local d slug
        for d in "$SITES_ROOT"/*; do
            [ -d "$d" ] || continue
            slug=$(basename "$d")
            [ "$slug" = "{{ $reverse_proxy_folder_name }}" ] && continue
            case "$slug" in
                *.backup|*.removed.*) continue ;;
            esac
            [ -f "$d/docker-compose.yml" ] || continue
            echo "$slug"
        done | sort -u
    }

    has_target() {
        local target="$1"
        case ",$CLEAN_TARGETS," in
            *",$target,"*) return 0 ;;
            *) return 1 ;;
        esac
    }

    cleanup_project_resources() {
        local project_dir="$1"
        local compose_file="$project_dir/docker-compose.yml"
        local project_slug
        local project_name

        project_slug=$(basename "$project_dir")

        if [ ! -f "$compose_file" ]; then
            echo "[WARN] Skip '$project_slug': docker-compose.yml not found"
            return 0
        fi

        project_name=$(docker compose -f "$compose_file" config --project-name 2>/dev/null || true)
        if [ -z "$project_name" ]; then
            project_name=$(echo "$project_slug" | tr -cd '[:alnum:]' | tr '[:upper:]' '[:lower:]')
        fi

        echo "[INFO] Cleaning project '$project_slug' (compose project: $project_name)"

        if has_target containers; then
            stopped_containers=$(docker ps -a \
                --filter "label=com.docker.compose.project=$project_name" \
                --filter "status=created" \
                --filter "status=exited" \
                --filter "status=dead" \
                --format '@{{.ID}}' || true)

            if [ -n "$stopped_containers" ]; then
                echo "$stopped_containers" | xargs -r docker rm -v || true
                echo "[INFO] Removed stopped containers for $project_name"
            fi
        fi

        if has_target images; then
            image_pattern="^${project_name}[-_]"
            candidate_images=$(docker image ls --format '@{{.Repository}}:@{{.Tag}}' | grep -E "$image_pattern" || true)

            if [ -n "$candidate_images" ]; then
                while read -r image_ref; do
                    [ -n "$image_ref" ] || continue
                    running_refs=$(docker ps --filter "ancestor=$image_ref" -q | wc -l | tr -d ' ')
                    if [ "$running_refs" = "0" ]; then
                        docker rmi "$image_ref" >/dev/null 2>&1 || true
                    fi
                done <<< "$candidate_images"
                echo "[INFO] Processed project images for $project_name"
            fi
        fi

        if has_target networks; then
            project_networks=$(docker network ls --filter "label=com.docker.compose.project=$project_name" -q || true)
            if [ -n "$project_networks" ]; then
                echo "$project_networks" | xargs -r docker network rm >/dev/null 2>&1 || true
                echo "[INFO] Processed project networks for $project_name"
            fi
        fi

        if has_target volumes; then
            project_volumes=$(docker volume ls --filter "label=com.docker.compose.project=$project_name" -q || true)
            if [ -n "$project_volumes" ]; then
                echo "$project_volumes" | xargs -r docker volume rm >/dev/null 2>&1 || true
                echo "[INFO] Processed project volumes for $project_name"
            fi
        fi
    }

    if [ -n "${CLEAN_TARGETS:-}" ]; then
        CLEAN_TARGETS=$(normalize_csv "$CLEAN_TARGETS")
    else
        CLEAN_TARGETS=$(normalize_csv "$CLEAN_TARGETS_RAW")
    fi

    if [ -z "$CLEAN_TARGETS" ]; then
        CLEAN_TARGETS="containers,images,networks,volumes"
    fi

    echo "🚀 Starting Docker cleanup"
    echo "[INFO] Scope: $CLEAN_SCOPE"
    echo "[INFO] Targets: $CLEAN_TARGETS"
    echo "[INFO] Projects input: ${CLEAN_PROJECTS_RAW:-<none>}"

    case "$CLEAN_SCOPE" in
        all)
            if has_target containers; then
                docker container prune -f
            fi

            if has_target images; then
                if [ "$CLEAN_IMAGES_MODE" = "dangling" ]; then
                    docker image prune -f
                else
                    docker image prune -a -f
                fi
            fi

            if has_target networks; then
                docker network prune -f
            fi

            if has_target volumes; then
                docker volume prune -f
            fi
            ;;
        current)
            cleanup_project_resources "$SITES_ROOT/$CURRENT_SITE"
            ;;
        projects)
            PROJECTS_CSV=$(normalize_csv "$CLEAN_PROJECTS_RAW")
            if [ -z "$PROJECTS_CSV" ]; then
                echo "[ERROR] Scope 'projects' requires DOCKER_CLEAN_PROJECTS (comma separated)."
                exit 1
            fi

            IFS=',' read -ra projects <<< "$PROJECTS_CSV"
            for project in "${projects[@]}"; do
                [ -n "$project" ] || continue
                cleanup_project_resources "$SITES_ROOT/$project"
            done
            ;;
        *)
            echo "[ERROR] Unknown DOCKER_CLEAN_SCOPE='$CLEAN_SCOPE'. Allowed: all|current|projects"
            exit 1
            ;;
    esac

    echo "✅ Docker cleanup completed."
    echo "[INFO] Tip: you can still override defaults via env vars if needed."
@endtask

@task('deploy_app_zero_downtime', ['on' => 'web_new'])
#!/bin/bash
set -euo pipefail

# ============================================================================
# HELPER FUNCTIONS
# ============================================================================

log() {
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] $1\033[0m"
}

error() {
    echo -e "\033[0;31m[$(date +'%Y-%m-%d %H:%M:%S')] ERROR: $1\033[0m" >&2
}

# Find Traefik container name
find_traefik_container() {
    # Strategy 1: via docker compose ps - scoped to current project only
    local container
    container=$(docker compose ps 2>/dev/null \
        | awk 'NR>1 && /traefik/ {print $1}' \
        | head -1)

    if [ -n "$container" ]; then
        echo "$container"
        return 0
    fi

    # Strategy 2: via compose config container_name - scoped to current project
    container=$(docker compose config --format json 2>/dev/null | jq -r '
        .services | to_entries[]
        | select(
            (.key | test("traefik"; "i"))
            or (.value.image? // "" | test("traefik"; "i"))
        )
        | .value.container_name? // empty
    ' | head -1)

    if [ -n "$container" ] && [ "$container" != "null" ]; then
        echo "$container"
        return 0
    fi

    # Strategy 3: fallback - global search when compose metadata is unavailable
    container=$(
        docker ps --filter "ancestor=traefik:latest" --format "@{{.Names}}" | head -1 || \
        docker ps --format "@{{.Names}}" | grep -i traefik | head -1
    )

    if [ -n "$container" ]; then
        echo "$container"
        return 0
    fi

    error "Traefik container not found"
    return 1
}

# Wait for Traefik to discover the container
wait_for_traefik_discovery() {
    local container_name=$1
    local timeout=60
    local counter=0

    local traefik_container=$(find_traefik_container)
    if [ -z "$traefik_container" ]; then
        error "Traefik container not found"
        return 1
    fi

    log "Using Traefik container: $traefik_container"
    log "Waiting for Traefik to discover $container_name..."

    while [ $counter -lt $timeout ]; do
        # Check if service appears in Traefik API
        if docker exec "$traefik_container" wget -qO- --timeout=2 "http://127.0.0.1:8080/api/http/services" 2>/dev/null | \
           grep -q "{{ $container_prefix }}app-${DEPLOYMENT_COLOR}@docker"; then
            log "Traefik discovered $container_name in $counter seconds"
            return 0
        fi

        # Try direct connectivity check
        if docker exec "$traefik_container" wget -qO- --timeout=2 \
           --header="Host: ${TRAEFIK_HOST}" \
           "http://{{ $container_prefix }}app-${DEPLOYMENT_COLOR}:9501/up" >/dev/null 2>&1; then
            log "Traefik can reach $container_name directly in $counter seconds"
            return 0
        fi

        sleep 1
        counter=$((counter + 1))
    done

    error "Traefik failed to discover $container_name within $timeout seconds"
    return 1
}

# Verify Traefik routing is working
verify_traefik_routing() {
    local container_name=$1
    local max_attempts=30
    local attempt=1

    local traefik_container=$(find_traefik_container)
    if [ -z "$traefik_container" ]; then
        error "Traefik container not found for routing verification"
        return 1
    fi

    log "Verifying Traefik routing to $container_name via $traefik_container..."

    while [ $attempt -le $max_attempts ]; do
        # Internal Traefik -> Container check
        if docker exec "$traefik_container" wget -qO- --timeout=3 \
           --header="Host: ${TRAEFIK_HOST}" \
           "http://{{ $container_prefix }}app-${DEPLOYMENT_COLOR}:9501/up" >/dev/null 2>&1; then
            log "Traefik routing verified in $attempt attempts"
            return 0
        fi

        # External HTTPS check
        if curl -f --max-time 3 --insecure -H "Host: ${TRAEFIK_HOST}" \
           "https://localhost/up" >/dev/null 2>&1; then
            log "External HTTPS routing verified in $attempt attempts"
            return 0
        fi

        sleep 1
        attempt=$((attempt + 1))
    done

    error "Traefik routing verification failed after $max_attempts attempts"
    return 1
}

# Setup weighted routing for gradual traffic switch
setup_weighted_routing() {
    local new_color=$1
    local current_color=$2

    local traefik_container=$(find_traefik_container)
    if [ -z "$traefik_container" ]; then
        error "Traefik container not found for weighted routing setup"
        return 1
    fi

    log "Setting up weighted routing: $current_color -> $new_color"

    if [ -n "$current_color" ]; then
        # Create weighted service with both old and new backends
        docker update \
            --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.old.service={{ $container_prefix }}app-$current_color" \
            --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.old.weight=100" \
            --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.new.service={{ $container_prefix }}app-$new_color" \
            --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.new.weight=0" \
            "{{ $container_prefix }}app-$new_color" 2>/dev/null || true

        # Create main router with highest priority pointing to weighted service
        docker update \
            --label-add="traefik.http.routers.{{ $container_prefix }}app-main.service={{ $container_prefix }}app-weighted" \
            --label-add="traefik.http.routers.{{ $container_prefix }}app-main.rule=Host(\`${TRAEFIK_HOST}\`)" \
            --label-add="traefik.http.routers.{{ $container_prefix }}app-main.entrypoints=websecure" \
            --label-add="traefik.http.routers.{{ $container_prefix }}app-main.tls=true" \
            --label-add="traefik.http.routers.{{ $container_prefix }}app-main.tls.certresolver=letsencrypt" \
            --label-add="traefik.http.routers.{{ $container_prefix }}app-main.priority=250" \
            --label-add="traefik.http.routers.{{ $container_prefix }}app-main.middlewares=app-compress,app-headers" \
            "{{ $container_prefix }}app-$new_color" 2>/dev/null || true

        # Lower individual router priorities
        docker update \
            --label-add="traefik.http.routers.{{ $container_prefix }}app-$current_color.priority=50" \
            "{{ $container_prefix }}app-$current_color" 2>/dev/null || true

        docker update \
            --label-add="traefik.http.routers.{{ $container_prefix }}app-$new_color.priority=50" \
            "{{ $container_prefix }}app-$new_color" 2>/dev/null || true
    fi

    sleep 5
    return 0
}

# Gradually shift traffic from old to new deployment
gradual_weight_switch() {
    local new_color=$1
    local current_color=$2

    if [ -z "$current_color" ]; then
        log "No current deployment, switching directly to $new_color"
        return 0
    fi

    local traefik_container=$(find_traefik_container)
    if [ -z "$traefik_container" ]; then
        error "Traefik container not found for weight switching"
        return 1
    fi

    log "Starting gradual weight switch from $current_color to $new_color"

    # Gradually increase traffic to new deployment: 20% -> 40% -> 60% -> 80% -> 100%
    for new_weight in 20 40 60 80 100; do
        old_weight=$((100 - new_weight))

        log "Traffic distribution: $current_color($old_weight%) -> $new_color($new_weight%)"

        # Update weights on both containers
        if docker ps --format "@{{.Names}}" | grep -q "^{{ $container_prefix }}app-$current_color"; then
            docker update \
                --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.old.weight=$old_weight" \
                --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.new.weight=$new_weight" \
                "{{ $container_prefix }}app-$current_color" 2>/dev/null || true
        fi

        docker update \
            --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.old.weight=$old_weight" \
            --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.new.weight=$new_weight" \
            "{{ $container_prefix }}app-$new_color" 2>/dev/null || true

        sleep 3

        # Health check at this weight level
        local health_check_attempts=5
        local health_success=false

        for i in $(seq 1 $health_check_attempts); do
            # Internal health check
            if docker exec "$traefik_container" wget -qO- --timeout=3 \
               --header="Host: ${TRAEFIK_HOST}" \
               "http://{{ $container_prefix }}app-${new_color}:9501/up" >/dev/null 2>&1; then
                log "Internal health check passed (attempt $i)"
                health_success=true
                break
            fi

            # External health check
            if curl -f --max-time 3 --insecure -H "Host: ${TRAEFIK_HOST}" \
               "https://localhost/up" >/dev/null 2>&1; then
                log "External health check passed (attempt $i)"
                health_success=true
                break
            fi

            sleep 1
        done

        if [ "$health_success" = false ]; then
            error "Health check failed at weight $new_weight, rolling back"

            # Rollback to old deployment
            if docker ps --format "@{{.Names}}" | grep -q "^{{ $container_prefix }}app-$current_color"; then
                docker update \
                    --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.old.weight=100" \
                    --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.new.weight=0" \
                    "{{ $container_prefix }}app-$current_color" 2>/dev/null || true
            fi

            docker update \
                --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.old.weight=100" \
                --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.new.weight=0" \
                "{{ $container_prefix }}app-$new_color" 2>/dev/null || true

            return 1
        fi

        sleep 5
    done

    log "Weight switch completed successfully"

    # Final verification
    log "Finalizing traffic configuration..."

    docker update \
        --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.old.weight=0" \
        --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.new.weight=100" \
        "{{ $container_prefix }}app-$new_color" 2>/dev/null || true

    if docker ps --format "@{{.Names}}" | grep -q "^{{ $container_prefix }}app-$current_color"; then
        docker update \
            --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.old.weight=0" \
            --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.new.weight=100" \
            "{{ $container_prefix }}app-$current_color" 2>/dev/null || true
    fi

    sleep 3

    # Final connectivity check
    local final_check_attempts=0
    while [ $final_check_attempts -lt 5 ]; do
        if docker exec "$traefik_container" wget -qO- --timeout=3 \
           --header="Host: ${TRAEFIK_HOST}" \
           "http://{{ $container_prefix }}app-${new_color}:9501/up" >/dev/null 2>&1; then
            log "✅ Final verification successful - weighted routing active with 100% on $new_color"
            return 0
        fi
        sleep 2
        final_check_attempts=$((final_check_attempts + 1))
    done

    error "❌ Final verification failed"
    return 1
}

# Warm up container by pre-caching and sending test requests
exec_www_data_with_secrets() {
    local container_name="$1"
    local command="$2"

    docker exec --user root \
        -e RUN_AS_WWW_DATA_COMMAND="$command" \
        "$container_name" \
        bash -lc '
            set -e

            if [ -n "${APP_KEY:-}" ] && [ -f "${APP_KEY}" ]; then
                export APP_KEY="$(cat "${APP_KEY}")"
            elif [ -f /run/secrets/app_key ]; then
                export APP_KEY="$(cat /run/secrets/app_key)"
            fi

            if [ -n "${DB_PASSWORD:-}" ] && [ -f "${DB_PASSWORD}" ]; then
                export DB_PASSWORD="$(cat "${DB_PASSWORD}")"
            elif [ -f /run/secrets/db_password ]; then
                export DB_PASSWORD="$(cat /run/secrets/db_password)"
            fi

            exec gosu www-data bash -lc "$RUN_AS_WWW_DATA_COMMAND"
        '
}

warmup_container() {
    local container_name=$1

    log "Warming up $container_name..."

    # Cache Laravel configs
    exec_www_data_with_secrets "$container_name" "php artisan config:cache" >/dev/null 2>&1 || true
    exec_www_data_with_secrets "$container_name" "php artisan route:cache" >/dev/null 2>&1 || true
    exec_www_data_with_secrets "$container_name" "php artisan view:cache" >/dev/null 2>&1 || true

    # Send test requests to warm up Octane workers
    for i in {1..10}; do
        docker exec "$container_name" curl -s --max-time 1 http://localhost:9501/up >/dev/null 2>&1 || true &
    done

    wait

    log "Container warmup completed"
}

# Rollback to previous deployment
rollback_deployment() {
    local old_color=$1
    local new_color=$2

    error "=== STARTING ROLLBACK PROCESS ==="
    error "Rolling back from $new_color to $old_color"

    local rollback_success=false

    if [ -n "$old_color" ] && docker ps --format "@{{.Names}}" | grep -q "^{{ $container_prefix }}app-$old_color"; then
        log "OLD CONTAINER EXISTS - Restoring traffic to $old_color"

        # Restore 100% traffic to old deployment
        docker update \
            --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.old.weight=100" \
            --label-add="traefik.http.services.{{ $container_prefix }}app-weighted.loadbalancer.weighted.services.new.weight=0" \
            "{{ $container_prefix }}app-$old_color" 2>/dev/null || true

        docker update \
            --label-add="traefik.http.routers.{{ $container_prefix }}app-$old_color.priority=200" \
            "{{ $container_prefix }}app-$old_color" 2>/dev/null || true

        docker update \
            --label-add="traefik.http.routers.{{ $container_prefix }}app-main.service={{ $container_prefix }}app-$old_color" \
            "{{ $container_prefix }}app-$old_color" 2>/dev/null || true

        sleep 5

        # Verify rollback
        local health_attempts=0
        while [ $health_attempts -lt 10 ]; do
            if curl -f --max-time 3 --insecure "https://${TRAEFIK_HOST}/up" >/dev/null 2>&1; then
                log "✅ Traffic successfully restored to $old_color"
                rollback_success=true
                break
            fi
            sleep 2
            health_attempts=$((health_attempts + 1))
        done

        if [ "$rollback_success" = "false" ]; then
            error "❌ Failed to restore traffic to $old_color even though container exists"
        fi
    else
        error "❌ CRITICAL: Old container {{ $container_prefix }}app-$old_color not found or not running"
        error "Manual intervention required!"
    fi

    # Remove failed new deployment containers
    log "Removing failed containers..."
    local containers_to_remove="{{ $container_prefix }}app-$new_color {{ $container_prefix }}queue-$new_color {{ $container_prefix }}seaweedfs-$new_color"

    for container in $containers_to_remove; do
        if docker ps -a --format "@{{.Names}}" | grep -q "^${container}$"; then
            log "Removing container: $container"
            docker stop --timeout=10 "$container" 2>/dev/null || true
            docker rm -f "$container" 2>/dev/null || true
        fi
    done

    log "Restoring secret files from rollback backup..."
    if ! bash -lc '
        set -euo pipefail
        SECRET_DIR="/var/secrets/{{ $site_name }}"
        SECRET_BACKUP_DIR="$SECRET_DIR/.rollback-backup"

        if [ ! -d "$SECRET_BACKUP_DIR" ]; then
            exit 0
        fi

        restore_secret_file() {
            local secret_basename="$1"
            local source_file="$SECRET_DIR/${secret_basename}.txt"
            local backup_file="$SECRET_BACKUP_DIR/${secret_basename}.txt"
            local missing_marker="$SECRET_BACKUP_DIR/${secret_basename}.missing"

            if [ -f "$backup_file" ]; then
                sudo cp "$backup_file" "$source_file"
                sudo chmod 600 "$source_file"
                sudo chown deploy:deploy "$source_file"
                return 0
            fi

            if [ -f "$missing_marker" ]; then
                sudo rm -f "$source_file"
            fi
        }

        restore_secret_file app_key
        restore_secret_file db_password
        restore_secret_file cf
    '; then
        error "Failed to restore secret files from rollback backup"
    else
        log "Secret files restored from rollback backup"
    fi

    if [ "$rollback_success" = "true" ]; then
        log "✅ ROLLBACK COMPLETED SUCCESSFULLY"
        return 0
    else
        error "❌ ROLLBACK FAILED - Manual intervention required"
        return 1
    fi
}

# ============================================================================
# MAIN DEPLOYMENT LOGIC
# ============================================================================

cd {{ $remote_html_path }} || {
    error "Failed to change directory to {{ $remote_html_path }}"
    exit 1
}

log "Starting zero-downtime deployment..."

# Read deployment color from .env (set by generate_production_compose task)
if [ ! -f ".env" ]; then
    error ".env file not found - run generate_production_compose first"
    exit 1
fi

DEPLOYMENT_COLOR=$(grep "^DEPLOYMENT_COLOR=" .env | cut -d'=' -f2)
TRAEFIK_HOST=$(grep "^TRAEFIK_HOST=" .env | cut -d'=' -f2)

if [ -z "$DEPLOYMENT_COLOR" ]; then
    error "DEPLOYMENT_COLOR not set in .env"
    exit 1
fi

if [ -z "$TRAEFIK_HOST" ]; then
    error "TRAEFIK_HOST not set in .env"
    exit 1
fi

export DEPLOYMENT_COLOR
export TRAEFIK_HOST

log "Deployment color from config: $DEPLOYMENT_COLOR"
log "Traefik host: $TRAEFIK_HOST"

# Detect current deployment
if docker ps --format "table @{{.Names}}" | grep -q "^{{ $container_prefix }}app-blue"; then
    CURRENT_COLOR="blue"
elif docker ps --format "table @{{.Names}}" | grep -q "^{{ $container_prefix }}app-green"; then
    CURRENT_COLOR="green"
else
    CURRENT_COLOR=""
fi

NEW_COLOR="$DEPLOYMENT_COLOR"

log "Current deployment: ${CURRENT_COLOR:-none}"
log "New deployment: $NEW_COLOR"

# Verify docker-compose.yml exists and is valid
if [ ! -f "docker-compose.yml" ]; then
    error "docker-compose.yml not found - run generate_production_compose first"
    exit 1
fi

log "Validating docker-compose.yml..."
if ! docker compose config >/dev/null 2>&1; then
    error "Invalid docker-compose.yml file"
    exit 1
fi

log "✓ docker-compose.yml is valid"

# Build images for new deployment
log "Building images for $NEW_COLOR deployment..."

mapfile -t SERVICES_TO_BUILD < <(docker compose config --services | grep -- "-$NEW_COLOR$")

if [ ${#SERVICES_TO_BUILD[@]} -eq 0 ]; then
    error "No colorized services found for deployment color $NEW_COLOR"
    exit 1
fi

for service in "${SERVICES_TO_BUILD[@]}"; do
    log "Building $service..."
    if ! docker compose build "$service"; then
        error "Failed to build $service"
        exit 1
    fi
done

log "✓ All images built successfully"

if docker compose config --services | grep -qx "postgres"; then
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Setting Postgres user password...\033[0m"

        POSTGRES_PASSWORD_SECRET_FILE="/var/secrets/{{ $site_name }}/db_password.txt"

        if [ ! -f "$POSTGRES_PASSWORD_SECRET_FILE" ]; then
            echo -e "\033[1;31m[ERROR] Postgres password secret file not found: $POSTGRES_PASSWORD_SECRET_FILE\033[0m"
            exit 1
        fi

        PASS=$(tr -d '\r\n' < "$POSTGRES_PASSWORD_SECRET_FILE")

        if [ -z "$PASS" ]; then
            echo -e "\033[1;31m[ERROR] Postgres password secret file is empty\033[0m"
            exit 1
        fi

        # Find Postgres container by compose service label (running or stopped)
        CONTAINER_NAME=$(docker ps -a --filter "label=com.docker.compose.service=postgres" --format "@{{.Names}}" | head -n 1)

        if [ -z "$CONTAINER_NAME" ]; then
            echo "Postgres container not found, creating via docker compose up -d postgres..."
            docker compose up -d postgres || {
                echo -e "\033[1;31m[ERROR] Failed to create Postgres container\033[0m"
                exit 1
            }
            sleep 5
            CONTAINER_NAME=$(docker ps -a --filter "label=com.docker.compose.service=postgres" --format "@{{.Names}}" | head -n 1)
        fi

        if [ -z "$CONTAINER_NAME" ]; then
            echo -e "\033[1;31m[ERROR] Postgres container not found after compose up\033[0m"
            exit 1
        fi

        echo "Found Postgres container: $CONTAINER_NAME"

        # Ensure container is running
        if [ "$(docker inspect -f '^@{{.State.Running}}' "$CONTAINER_NAME")" != "true" ]; then
            echo "Starting Postgres container..."
            docker start "$CONTAINER_NAME" || {
                echo -e "\033[1;31m[ERROR] Failed to start Postgres container\033[0m"
                exit 1
            }
            sleep 5
        fi

        # Set password for Postgres user from the canonical host secret file.
        docker exec "$CONTAINER_NAME" sh -c "
            psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" \
            -c \"ALTER USER \$POSTGRES_USER WITH PASSWORD '$PASS';\"
        " || {
            echo -e "\033[1;31m[ERROR] Failed to set Postgres user password\033[0m"
            exit 1
        }

        echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] ✓ Postgres password updated successfully\033[0m"
else
    echo -e "\033[0;34m[INFO] Postgres service is not part of the rendered stack, skipping password sync\033[0m"
fi

# Start new containers
log "Starting new containers: ${SERVICES_TO_BUILD[*]}"

if ! docker compose up -d --no-build "${SERVICES_TO_BUILD[@]}"; then
    error "Failed to start new containers"
    exit 1
fi

log "✓ New containers started"

# Wait for containers to initialize
log "Waiting for containers to initialize..."
sleep 5

# Verify containers are running
for service in "${SERVICES_TO_BUILD[@]}"; do
    CONTAINER_NAME="{{ $container_prefix }}${service}"

    if ! docker inspect "$CONTAINER_NAME" >/dev/null 2>&1; then
        error "Container $CONTAINER_NAME does not exist"
        rollback_deployment "$CURRENT_COLOR" "$NEW_COLOR"
        exit 1
    fi

    CONTAINER_STATUS=$(docker inspect --format='@{{.State.Status}}' "$CONTAINER_NAME")
    if [ "$CONTAINER_STATUS" != "running" ]; then
        error "Container $CONTAINER_NAME is not running (status: $CONTAINER_STATUS)"
        docker logs "$CONTAINER_NAME" --tail 50 2>/dev/null || true
        rollback_deployment "$CURRENT_COLOR" "$NEW_COLOR"
        exit 1
    fi

    log "✓ $CONTAINER_NAME is running"
done

# Health check new app container
log "Performing health check on new deployment..."

APP_CONTAINER="{{ $container_prefix }}app-$NEW_COLOR"
TIMEOUT=30
COUNTER=0

while [ $COUNTER -lt $TIMEOUT ]; do
    HEALTH_STATUS=$(docker inspect --format='@{{.State.Health.Status}}' "$APP_CONTAINER" 2>/dev/null || echo "none")

    if [ "$HEALTH_STATUS" = "healthy" ]; then
        log "✓ Docker healthcheck passed in $COUNTER seconds"
        break
    elif [ "$HEALTH_STATUS" = "none" ] || [ "$HEALTH_STATUS" = "starting" ]; then
        # Try direct check if Docker health not available
        if docker exec "$APP_CONTAINER" curl -f --max-time 2 http://localhost:9501/up >/dev/null 2>&1; then
            log "✓ Direct health check passed in $COUNTER seconds"
            break
        fi
    fi

    sleep 1
    COUNTER=$((COUNTER + 1))
done

if [ $COUNTER -ge $TIMEOUT ]; then
    error "New container failed to become healthy within $TIMEOUT seconds"
    log "Container logs:"
    docker logs "$APP_CONTAINER" --tail 50
    rollback_deployment "$CURRENT_COLOR" "$NEW_COLOR"
    exit 1
fi

# Warm up the new container
warmup_container "$APP_CONTAINER"

# Wait for Traefik to discover new service
if ! wait_for_traefik_discovery "$APP_CONTAINER"; then
    error "Traefik discovery failed"
    rollback_deployment "$CURRENT_COLOR" "$NEW_COLOR"
    exit 1
fi

# Verify Traefik routing
if ! verify_traefik_routing "$APP_CONTAINER"; then
    error "Traefik routing verification failed"
    rollback_deployment "$CURRENT_COLOR" "$NEW_COLOR"
    exit 1
fi

# Normal blue-green traffic switch
if ! setup_weighted_routing "$NEW_COLOR" "$CURRENT_COLOR"; then
    error "Failed to setup weighted routing"
    rollback_deployment "$CURRENT_COLOR" "$NEW_COLOR"
    exit 1
fi

if ! gradual_weight_switch "$NEW_COLOR" "$CURRENT_COLOR"; then
    error "Gradual traffic switch failed"
    rollback_deployment "$CURRENT_COLOR" "$NEW_COLOR"
    exit 1
fi

# Gracefully stop old queue workers if exists
if [ -n "$CURRENT_COLOR" ]; then
    if docker ps --format "table @{{.Names}}" | grep -q "^{{ $container_prefix }}queue-$CURRENT_COLOR"; then
        log "Gracefully stopping old queue worker..."
        exec_www_data_with_secrets "{{ $container_prefix }}queue-$CURRENT_COLOR" "php artisan queue:restart" 2>/dev/null || true
        sleep 10
    fi
fi

# Wait for ongoing requests to complete on old deployment
if [ -n "$CURRENT_COLOR" ]; then
    if docker ps --format "table @{{.Names}}" | grep -q "^{{ $container_prefix }}app-$CURRENT_COLOR"; then
        log "Waiting for ongoing requests to complete..."
        sleep 15
    fi
fi

# Final verification before removing old containers
log "Final verification before cleanup..."

VERIFICATION_FAILED=false

# Check HAProxy
log "Checking HAProxy HTTPS (port 443)..."
HTTPS_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" -k -H "Host: ${TRAEFIK_HOST}" --max-time 5 "https://127.0.0.1:443/up" 2>&1)
if [ "$HTTPS_RESPONSE" = "200" ]; then
    log "✓ HAProxy HTTPS responds with code: $HTTPS_RESPONSE"
else
    error "✗ HAProxy HTTPS returned: $HTTPS_RESPONSE (expected 200)"
    VERIFICATION_FAILED=true
fi

# Check Traefik to container connectivity
log "Checking Traefik to container connectivity..."
TRAEFIK_CONTAINER=$(find_traefik_container)
if [ -n "$TRAEFIK_CONTAINER" ]; then
    if docker exec "$TRAEFIK_CONTAINER" wget -qO- --timeout=3 \
       --header="Host: ${TRAEFIK_HOST}" \
       "http://{{ $container_prefix }}app-$NEW_COLOR:9501/up" >/dev/null 2>&1; then
        log "✓ Traefik can reach new container"
    else
        error "✗ Traefik cannot reach new container"
        VERIFICATION_FAILED=true
    fi
fi

if [ "$VERIFICATION_FAILED" = "true" ]; then
    error "Final verification failed - rolling back"
    rollback_deployment "$CURRENT_COLOR" "$NEW_COLOR"
    exit 1
fi

log "✓ Final verification passed - safe to remove old containers"

# Remove old deployment containers
if [ -n "$CURRENT_COLOR" ]; then
    OLD_CONTAINERS=(
        "{{ $container_prefix }}app-$CURRENT_COLOR"
        "{{ $container_prefix }}queue-$CURRENT_COLOR"
        "{{ $container_prefix }}seaweedfs-$CURRENT_COLOR"
    )

    log "Removing old deployment containers: ${OLD_CONTAINERS[*]}"

    for container in "${OLD_CONTAINERS[@]}"; do
        if docker ps -a --format "@{{.Names}}" | grep -q "^${container}$"; then
            log "Stopping and removing: $container"
            docker stop "$container" 2>/dev/null || true
            docker rm "$container" 2>/dev/null || true
        fi
    done

    log "✓ Old containers removed"
fi

log "Reconciling stack and pruning orphaned services..."
if ! docker compose up -d --remove-orphans; then
    error "Failed to reconcile stack after cleanup"
    exit 1
fi

# Ensure Traefik is running
TRAEFIK_CONTAINER=$(docker ps --format '@{{.Names}}' | grep -E "^{{ $container_prefix }}traefik" | head -n1)

if [ -z "$TRAEFIK_CONTAINER" ]; then
    log "Traefik not running, starting it..."
    if ! docker compose up -d traefik; then
        error "Failed to start Traefik"
        exit 1
    fi

    # Wait for Traefik to be ready
    timeout 60 sh -c 'while ! docker compose ps traefik | grep -q "Up"; do sleep 1; done' || {
        error "Traefik failed to start within timeout"
        exit 1
    }

    log "✓ Traefik is ready"
else
    log "✓ Traefik already running ($TRAEFIK_CONTAINER)"
fi

# Final post-cleanup verification
log "Final post-cleanup verification..."
sleep 5

if ! curl -f --max-time 5 --insecure "https://${TRAEFIK_HOST}/up" >/dev/null 2>&1; then
    error "CRITICAL: Application is down after cleanup!"
    exit 1
fi

log "✓ Application is accessible"

# Calculate deployment time
DEPLOYMENT_TIMESTAMP=$(grep "^DEPLOYMENT_TIMESTAMP=" .env | cut -d'=' -f2)
DEPLOYMENT_TIME=$(($(date +%s) - DEPLOYMENT_TIMESTAMP))

log ""
log "========================================="
log "✅ ZERO-DOWNTIME DEPLOYMENT SUCCESSFUL"
log "========================================="
log "🎯 Active deployment: $NEW_COLOR"
log "🌐 Application URL: https://${TRAEFIK_HOST}/"
log "⏱️  Total deployment time: ${DEPLOYMENT_TIME} seconds"
log "📊 Deployment timestamp: $DEPLOYMENT_TIMESTAMP"
log "========================================="
@endtask

@task('list_sites', ['on' => 'web_new'])
    echo "Deployed sites on server:"
    ls -1 /var/www/ | grep -v html | while read site; do
        echo "  - $site"
        docker ps --filter "name=^${site}_app-" --format "    @{{.Names}}: @{{.Status}}"
    done
@endtask

@task('ensure_haproxy_running', ['on' => 'web_new'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Ensuring HAProxy is running...\033[0m"

    REVERSE_PROXY_DIR="{{ $sites_root }}/{{ $reverse_proxy_folder_name }}"

    if [ ! -d "$REVERSE_PROXY_DIR" ] || [ ! -f "$REVERSE_PROXY_DIR/docker-compose.yml" ]; then
        echo "⚠️  reverse-proxy compose not found at $REVERSE_PROXY_DIR, skipping ensure_haproxy_running"
        exit 0
    fi

    cd "$REVERSE_PROXY_DIR"

    if ! docker compose config --services | grep -qx "haproxy"; then
        echo "⚠️  Service 'haproxy' is not defined in $REVERSE_PROXY_DIR/docker-compose.yml, skipping"
        exit 0
    fi

    if docker compose ps haproxy 2>/dev/null | grep -q "Up"; then
        echo "✅ HAProxy already running and healthy"
    else
        echo "HAProxy not running, starting it..."

        if docker compose up -d haproxy; then
            echo "HAProxy started, waiting for health check..."

            for i in {1..30}; do
                STATUS=$(docker compose ps haproxy --format json 2>/dev/null | jq -r '.[0].Health // "none"')

                if [ "$STATUS" = "healthy" ] || [ "$STATUS" = "none" ]; then
                    echo "✅ HAProxy is ready"
                    break
                fi

                if [ $i -eq 30 ]; then
                    echo "⚠️  HAProxy health check timeout, but continuing"
                fi

                sleep 1
            done
        else
            echo "⚠️  Could not start HAProxy (dependencies may not be ready)"
            echo "This is not critical - HAProxy will be started later in deploy_haproxy task"
        fi
    fi
@endtask

@task('post-deploy', ['on' => 'production'])

    cd {{ $remote_html_path }}
    if php artisan help app:post-deploy >/dev/null 2>&1; then
        php artisan app:post-deploy
    else
        echo -e "\033[0;33m[INFO] Artisan command 'app:post-deploy' not available on remote; skipping.\033[0m"
    fi
@endtask

@task('set_postgres_password', ['on' => 'web_new'])
    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] Setting Postgres user password...\033[0m"

    if ! docker compose config --services | grep -qx "postgres"; then
        echo -e "\033[0;34m[INFO] Postgres service is not part of the rendered stack, skipping password update\033[0m"
        exit 0
    fi

    # Find Postgres container by compose service label (running or stopped)
    CONTAINER_NAME=$(docker ps -a --filter "label=com.docker.compose.service=postgres" --format "@{{.Names}}" | head -n 1)

    if [ -z "$CONTAINER_NAME" ]; then
        echo "Postgres container not found, creating via docker compose up -d postgres..."
        docker compose up -d postgres || {
            echo -e "\033[1;31m[ERROR] Failed to create Postgres container\033[0m"
            exit 1
        }
        sleep 5
        CONTAINER_NAME=$(docker ps -a --filter "label=com.docker.compose.service=postgres" --format "@{{.Names}}" | head -n 1)
    fi

    if [ -z "$CONTAINER_NAME" ]; then
        echo -e "\033[1;31m[ERROR] Postgres container not found after compose up\033[0m"
        exit 1
    fi

    echo "Found Postgres container: $CONTAINER_NAME"

    # Ensure container is running
    if [ "$(docker inspect -f '^@{{.State.Running}}' "$CONTAINER_NAME")" != "true" ]; then
        echo "Starting Postgres container..."
        docker start "$CONTAINER_NAME" || {
            echo -e "\033[1;31m[ERROR] Failed to start Postgres container\033[0m"
            exit 1
        }
        sleep 5
    fi

    POSTGRES_PASSWORD_SECRET_FILE="/var/secrets/{{ $site_name }}/db_password.txt"

    if [ ! -f "$POSTGRES_PASSWORD_SECRET_FILE" ]; then
        echo -e "\033[1;31m[ERROR] Postgres password secret file not found: $POSTGRES_PASSWORD_SECRET_FILE\033[0m"
        exit 1
    fi

    PASS=$(tr -d '\r\n' < "$POSTGRES_PASSWORD_SECRET_FILE")

    if [ -z "$PASS" ]; then
        echo -e "\033[1;31m[ERROR] Postgres password secret file is empty\033[0m"
        exit 1
    fi

    # Set password for Postgres user from the canonical host secret file.
    docker exec "$CONTAINER_NAME" sh -c "
        psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" \
        -c \"ALTER USER \$POSTGRES_USER WITH PASSWORD '$PASS';\"
    " || {
        echo -e "\033[1;31m[ERROR] Failed to set Postgres user password\033[0m"
        exit 1
    }

    echo -e "\033[0;32m[$(date +'%Y-%m-%d %H:%M:%S')] ✓ Postgres password updated successfully\033[0m"
@endtask
