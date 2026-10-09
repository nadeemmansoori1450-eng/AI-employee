/**
 * Generates and retrieves a 24-hour Bearer token for API authentication.
 * Uses WordPress Transients to cache the token for 86400 seconds (24 hours).
 */
function get_external_api_bearer_token()
{
    $transient_key = 'custom_api_bearer_token_24h';
    $token = get_transient($transient_key);

    // If token does not exist or has expired, generate a new one
    if (false === $token) {

        // TODO: Replace with your actual Authentication API Endpoint
        $auth_url = 'https://another-website.com/api/generate-token';

        // TODO: Add the required credentials for token generation (Client ID, Secret, etc.)
        $auth_payload = array(
            'client_id'     => 'YOUR_CLIENT_ID',
            'client_secret' => 'YOUR_CLIENT_SECRET',
            'grant_type'    => 'client_credentials'
        );

        $auth_response = wp_remote_post($auth_url, array(
            'method'      => 'POST',
            'timeout'     => 15,
            'blocking'    => true,
            'headers'     => array(
                'Content-Type' => 'application/json',
            ),
            'body'        => wp_json_encode($auth_payload),
        ));

        // Validate the response and extract the token
        if (! is_wp_error($auth_response) && wp_remote_retrieve_response_code($auth_response) === 200) {
            $auth_body = json_decode(wp_remote_retrieve_body($auth_response), true);

            if (isset($auth_body['access_token'])) {
                $token = $auth_body['access_token'];

                // Cache the token in WordPress for 24 hours (24 * 60 * 60 = 86400 seconds)
                set_transient($transient_key, $token, 86400);
            }
        }
    }

    return $token;
}

/**
 * Captures Contact Form 7 submission before mail is sent to preserve all field tags and context. API Code Start
 */
/**
 * Captures Contact Form 7 submission before mail is sent to preserve all field tags and context.
 */
add_action('wpcf7_before_send_mail', 'push_cf7_data_to_external_api', 20, 3);

function push_cf7_data_to_external_api($contact_form, &$abort, $submission)
{
    if (!$submission) {
        $submission = WPCF7_Submission::get_instance();
    }

    if ($submission) {
        $posted_data = $submission->get_posted_data();

        // --- FILE UPLOAD (RESUME) HANDLING ---
        $uploaded_files = $submission->uploaded_files();
        $resume_public_url = '';

        if (!empty($uploaded_files['file-527'])) {
            $temp_file_path = $uploaded_files['file-527'][0];

            $wp_upload_dir = wp_upload_dir();
            $target_dir = $wp_upload_dir['basedir'] . '/job_resumes';
            $target_url = $wp_upload_dir['baseurl'] . '/job_resumes';

            if (!file_exists($target_dir)) {
                wp_mkdir_p($target_dir);
            }

            $file_name = time() . '_' . basename($temp_file_path);
            $final_target_path = $target_dir . '/' . $file_name;

            if (copy($temp_file_path, $final_target_path)) {
                $resume_public_url = $target_url . '/' . $file_name;
            }
        }

        // --- SANITIZE FORM FIELDS ---
        $name         = !empty($posted_data['Name']) ? sanitize_text_field(is_array($posted_data['Name']) ? $posted_data['Name'][0] : $posted_data['Name']) : '';
        $email        = !empty($posted_data['Email']) ? sanitize_email(is_array($posted_data['Email']) ? $posted_data['Email'][0] : $posted_data['Email']) : '';
        $phone        = !empty($posted_data['Phone-Number']) ? sanitize_text_field(is_array($posted_data['Phone-Number']) ? $posted_data['Phone-Number'][0] : $posted_data['Phone-Number']) : '';
        $location     = !empty($posted_data['Location']) ? sanitize_text_field(is_array($posted_data['Location']) ? $posted_data['Location'][0] : $posted_data['Location']) : '';
        $organisation = !empty($posted_data['Current-Organisation']) ? sanitize_text_field(is_array($posted_data['Current-Organisation']) ? $posted_data['Current-Organisation'][0] : $posted_data['Current-Organisation']) : '';
        $role         = !empty($posted_data['Current-Role']) ? sanitize_text_field(is_array($posted_data['Current-Role']) ? $posted_data['Current-Role'][0] : $posted_data['Current-Role']) : '';
        $message      = !empty($posted_data['Message']) ? sanitize_textarea_field(is_array($posted_data['Message']) ? $posted_data['Message'][0] : $posted_data['Message']) : '';

        $can_commute_raw = isset($posted_data['Are-you-able-commute-job']) ? $posted_data['Are-you-able-commute-job'] : '';
        $can_commute  = !empty($can_commute_raw) ? (is_array($can_commute_raw) ? sanitize_text_field($can_commute_raw[0]) : sanitize_text_field($can_commute_raw)) : 'N/A';

        // --- PAGE URL FETCHING (KEY FOR PORTAL POSITION MAPPING) ---
        $page_url = '';
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $page_url = strtok(esc_url_raw($_SERVER['HTTP_REFERER']), '?');
        }

        // --- JOB TITLE & POSITION ID RESOLUTION ---
        $job_title = '';
        $position_id = 0;

        if (!empty($posted_data['job_title'])) {
            $job_title = is_array($posted_data['job_title']) ? sanitize_text_field($posted_data['job_title'][0]) : sanitize_text_field($posted_data['job_title']);
        }
        if (!empty($posted_data['job_id'])) {
            $position_id = intval(is_array($posted_data['job_id']) ? $posted_data['job_id'][0] : $posted_data['job_id']);
        }

        if (empty(trim($job_title)) || empty($position_id)) {
            $resolved_post_id = 0;

            $resolved_post_id = $submission->get_meta('container_post_id');

            if (!$resolved_post_id && isset($_POST['_wpcf7_container_post'])) {
                $resolved_post_id = intval($_POST['_wpcf7_container_post']);
            }

            if (!$resolved_post_id && !empty($page_url)) {
                $resolved_post_id = url_to_postid($page_url);
            }

            if ($resolved_post_id) {
                if (empty($position_id)) {
                    $position_id = $resolved_post_id;
                }
                if (empty(trim($job_title))) {
                    $job_title = get_the_title($resolved_post_id);
                }
            }
        }

        if (empty(trim($job_title))) {
            $job_title = 'General Application';
        }

        $clean_job_title = html_entity_decode($job_title, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // UTM tracking fields capture
        $utm_source   = !empty($posted_data['utm_source']) ? sanitize_text_field(is_array($posted_data['utm_source']) ? $posted_data['utm_source'][0] : $posted_data['utm_source']) : '';
        $utm_medium   = !empty($posted_data['utm_medium']) ? sanitize_text_field(is_array($posted_data['utm_medium']) ? $posted_data['utm_medium'][0] : $posted_data['utm_medium']) : '';
        $utm_campaign = !empty($posted_data['utm_campaign']) ? sanitize_text_field(is_array($posted_data['utm_campaign']) ? $posted_data['utm_campaign'][0] : $posted_data['utm_campaign']) : '';

        // Prepare JSON payload with page_url for portal position mapping
        $api_payload = array(
            'position_id'          => $position_id,
            'page_url'             => $page_url,
            'job_title'            => $clean_job_title,
            'name'                 => $name,
            'email'                => $email,
            'phone_number'         => $phone,
            'location'             => $location,
            'able_commute_job'     => $can_commute,
            'current_organisation' => $organisation,
            'current_role'         => $role,
            'message'              => $message,
            'resume_url'           => $resume_public_url,
            'utm_source'           => $utm_source,
            'utm_medium'           => $utm_medium,
            'utm_campaign'         => $utm_campaign
        );

        // Retrieve the Bearer token
        $bearer_token = get_external_api_bearer_token();

        // Target API URL (Aapka naya webhook endpoint)
        // Target API URL
        $target_api_url = 'https://app.selectionsearch.in/backend/web/receive-application.php';

        // Execute API POST Request
        $response = wp_remote_post($target_api_url, array(
            'method'      => 'POST',
            'timeout'     => 30,
            'blocking'    => true, // Temporary true rakhein response dekhne ke liye
            'sslverify'   => false, // SSL handshake issue prevent karne ke liye
            'headers'     => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $bearer_token,
            ),
            'body'        => wp_json_encode($api_payload),
        ));

        // Response check log
        if (is_wp_error($response)) {
            error_log('API Error: ' . $response->get_error_message());
        } else {
            error_log('API Status: ' . wp_remote_retrieve_response_code($response));
            error_log('API Body: ' . wp_remote_retrieve_body($response));
        }
    }
}



///////// reciver api conterller code 
<?php
header('Content-Type: application/json; charset=UTF-8');

function write_log($msg) {
    file_put_contents(__DIR__ . '/api_debug.log', date('[Y-m-d H:i:s] ') . print_r($msg, true) . PHP_EOL, FILE_APPEND);
}

write_log("--- NEW INCOMING REQUEST ---");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

// 1. Direct DB Connection
try {
    $common_main_local = __DIR__ . '/../../common/config/main-local.php';$db_config = [];
    if (file_exists($common_main_local)) {
        $config = require$common_main_local;
        $db_config =$config['components']['db'] ?? [];
    }

    $dsn      =$db_config['dsn'] ?? 'mysql:host=localhost;dbname=appselectionsear_app;charset=utf8mb4';
    $username =$db_config['username'] ?? 'root';
    $password =$db_config['password'] ?? '';

    $pdo = new PDO($dsn, $username,$password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    write_log("DB Connected Successfully");
} catch (\Throwable $t) {
    write_log("DB Connection Failed: " . $t->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $t->getMessage()]);
    exit;
}

// 2. Read Payload
$raw_body = file_get_contents('php://input');
write_log("Payload: " . $raw_body);
$data = json_decode($raw_body, true);

if (empty($data)) {
    write_log("Empty JSON Received");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty JSON']);
    exit;
}

$page_url    = isset($data['page_url']) ? trim($data['page_url']) : '';$job_title   = isset($data['job_title']) ? trim($data['job_title']) : '';
$resume_url  = isset($data['resume_url']) ? trim($data['resume_url']) : '';$position_id = null;

// 3. Simple & Robust Position Search
// Step A: By URL in positions table
if (!empty($page_url)) {
    $clean_url = rtrim(urldecode($page_url), '/');
    $stmt =$pdo->prepare("SELECT `id` FROM `positions` WHERE `website_url` LIKE :url LIMIT 1");
    $stmt->execute([':url' => '\%' . basename($clean_url) . '%']);
    $position_id =$stmt->fetchColumn();
    if ($position_id) {
        write_log("Position Matched via URL: " . $position_id);
    }
}

// Step B: By Title keywords
if (!$position_id && (!empty($job_title) \vert{}\vert{} !empty($page_url))) {
    $search_text = !empty($job_title) ? $job_title : basename(rtrim(urldecode($page_url), '/'));
    // Sirf alphanumeric words nikaalein
    preg_match_all('/\b[a-zA-Z]{3,}\b/', strtolower($search_text),$matches);
    $words = array_diff($matches[0] ?? [], ['jobs', 'job', 'careers', 'career']);

    if (!empty($words)) {$where = [];
        $params = [];$i = 0;
        foreach ($words as $w) {$key = ":w" . $i++;$where[] = "LOWER(`title`) LIKE {$key}";
            $params[$key] = '\%' .$w . '%';
        }

        $stmt =$pdo->prepare("SELECT `id` FROM `positions` WHERE " . implode(' AND ', $where) . " LIMIT 1");
        $stmt->execute($params);
        $position_id =$stmt->fetchColumn();
        if ($position_id) {
            write_log("Position Matched via Keywords (" . implode(', ', $words) . "): " . $position_id);
        }
    }
}

$matched_position_id = $position_id ? intval($position_id) : null;
write_log("Resolved Position ID: " . var_export($matched_position_id, true));

// 4. Insert Candidate Record
$current_time = date('Y-m-d H:i:s');$original_filename = !empty($resume_url) ? basename(parse_url($resume_url, PHP_URL_PATH)) : null;
$candidate_id = null;

try {
    $stmt_cand =$pdo->prepare("
        INSERT INTO `candidates` (
            `full_name`, `original_filename`, `email`, `phone`, `current_role`, `current_school`, 
            `city`, `resume_path`, `source`, `created_at`, `updated_at`
        ) VALUES (
            :name, :filename, :email, :phone, :role, :school,
            :city, :resume, 'upload', :created_at, :updated_at
        )
    ");

    $stmt_cand->execute([
        ':name'       => !empty($data['name']) ?$data['name'] : 'Applicant',
        ':filename'   => $original_filename,
        ':email'      => !empty($data['email']) ?$data['email'] : null,
        ':phone'      => !empty($data['phone_number']) ?$data['phone_number'] : null,
        ':role'       => !empty($data['current_role']) ?$data['current_role'] : null,
        ':school'     => !empty($data['current_organisation']) ?$data['current_organisation'] : null,
        ':city'       => !empty($data['location']) ?$data['location'] : null,
        ':resume'     => !empty($resume_url) ?$resume_url : null,
        ':created_at' => $current_time,
        ':updated_at' => $current_time
    ]);

    $candidate_id = (int)$pdo->lastInsertId();
    write_log("Candidate Inserted with ID: " . $candidate_id);
} catch (\Throwable $e) {
    write_log("Candidate Insert Failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}

// 5. Map to Position
if ($candidate_id &&$matched_position_id) {
    try {
        $stmt_pos =$pdo->prepare("
            INSERT INTO `candidate_positions` (
                `candidate_id`, `position_id`, `added_by`, `created_at`
            ) VALUES (
                :candidate_id, :position_id, 1, :created_at
            )
        ");
        $stmt_pos->execute([
            ':candidate_id' => $candidate_id,
            ':position_id'  => $matched_position_id,
            ':created_at'   => $current_time
        ]);
        write_log("Candidate {$candidate_id} Linked to Position {$matched_position_id}");
    } catch (\Throwable $e) {
        write_log("Position Link Failed: " . $e->getMessage());
    }
} else {
    write_log("Position Mapping Skipped (Position ID is null)");
}

// 6. Resume Parse Queue
if ($candidate_id) {
    try {
        $stmt_q =$pdo->prepare("
            INSERT INTO `resume_parse_queue` (`candidate_id`, `status`, `created_at`)
            VALUES (:candidate_id, 'pending', :created_at)
        ");
        $stmt_q->execute([
            ':candidate_id' => $candidate_id,
            ':created_at'   => $current_time
        ]);
        write_log("Candidate {$candidate_id} Added to resume_parse_queue");
    } catch (\Throwable $e) {
        write_log("Queue Insert Note: " . $e->getMessage());
    }
}

http_response_code(200);
echo json_encode([
    'status'              => 'success',
    'candidate_id'        => $candidate_id,
    'matched_position_id' => $matched_position_id
]);





// add_action('wpcf7_before_send_mail', 'ss_direct_cf7_to_ats', 20, 3);

// function ss_direct_cf7_to_ats($contact_form, &$abort, $submission) {
//     if (!$submission) {
//         $submission = WPCF7_Submission::get_instance();
//     }
//     if (!$submission) {
//         return;
//     }

//     $posted_data = $submission->get_posted_data();

//     // Fields extraction
//     $full_name    = isset($posted_data['Name']) ? (is_array($posted_data['Name']) ? $posted_data['Name'][0] : $posted_data['Name']) : '';
//     $email        = isset($posted_data['Email']) ? (is_array($posted_data['Email']) ? $posted_data['Email'][0] : $posted_data['Email']) : '';
//     $phone        = isset($posted_data['Phone-Number']) ? (is_array($posted_data['Phone-Number']) ? $posted_data['Phone-Number'][0] : $posted_data['Phone-Number']) : '';
//     $location     = isset($posted_data['Location']) ? (is_array($posted_data['Location']) ? $posted_data['Location'][0] : $posted_data['Location']) : '';
//     $can_commute  = isset($posted_data['Are-you-able-commute-job']) ? (is_array($posted_data['Are-you-able-commute-job']) ? $posted_data['Are-you-able-commute-job'][0] : $posted_data['Are-you-able-commute-job']) : '';
//     $current_org  = isset($posted_data['Current-Organisation']) ? (is_array($posted_data['Current-Organisation']) ? $posted_data['Current-Organisation'][0] : $posted_data['Current-Organisation']) : '';
//     $current_role = isset($posted_data['Current-Role']) ? (is_array($posted_data['Current-Role']) ? $posted_data['Current-Role'][0] : $posted_data['Current-Role']) : '';
//     $note         = isset($posted_data['Message']) ? (is_array($posted_data['Message']) ? $posted_data['Message'][0] : $posted_data['Message']) : '';

//     // File resume
//     $uploaded_files = $submission->uploaded_files();
//     $resume_files   = $uploaded_files['file-527'] ?? [];
//     $resume_path    = is_array($resume_files) ? ($resume_files[0] ?? null) : $resume_files;

//     // URL & Job Title
//     $referer_url = !empty($_SERVER['HTTP_REFERER']) ? strtok(esc_url_raw($_SERVER['HTTP_REFERER']), '?') : '';
//     $job_title = '';

//     if ($referer_url) {
//         $post_id = url_to_postid($referer_url);
//         if ($post_id) {
//             $job_title = get_the_title($post_id);
//         }
//     }

//     if (empty($job_title) && !empty($posted_data['job_title'])) {
//         $job_title = is_array($posted_data['job_title']) ? $posted_data['job_title'][0] : $posted_data['job_title'];
//     }

//     // Exact Token
//     $api_token = 'aQk_oX3ifDHc3GzpgIOFqtZW1C0TqyjTOs5VES5gtL04iwsO';

//     // Query parameters add kiye hain taaki agar Apache Header drop kare toh bhi token pahunch jaye
//     $endpoint  = 'https://app.selectionsearch.in/backend/web/api/lead-intake/website?access-token=' . urlencode($api_token) . '&token=' . urlencode($api_token);

//     $boundary  = wp_generate_password(24, false);
//     $body      = '';

//     $fields = [
//         'api_token'     => $api_token, // Body me bhi token include kiya
//         'token'         => $api_token,
//         'source_ref_id' => ($submission->get_meta('unit_tag') ?: 'cf7') . '-' . time(),
//         'full_name'     => $full_name,
//         'email'         => $email,
//         'phone'         => $phone,
//         'website_url'   => $referer_url,
//         'job_title'     => $job_title,
//         'current_role'  => $current_role,
//         'current_org'   => $current_org,
//         'location'      => $location,
//         'can_commute'   => $can_commute,
//         'note'          => $note,
//     ];

//     foreach ($fields as $name => $value) {
//         if ($value !== null) {
//             $body .= "--{$boundary}\r\n";
//             $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
//             $body .= "{$value}\r\n";
//         }
//     }

//     if ($resume_path && file_exists($resume_path)) {
//         $filename = basename($resume_path);
//         $body .= "--{$boundary}\r\n";
//         $body .= "Content-Disposition: form-data; name=\"resume_file\"; filename=\"{$filename}\"\r\n";
//         $body .= "Content-Type: application/pdf\r\n\r\n";
//         $body .= file_get_contents($resume_path) . "\r\n";
//     }

//     $body .= "--{$boundary}--\r\n";

//     $response = wp_remote_post($endpoint, [
//         'headers'   => [
//             'Authorization'       => 'Bearer ' . $api_token,
//             'X-Api-Key'           => $api_token,
//             'Content-Type'        => 'multipart/form-data; boundary=' . $boundary,
//         ],
//         'body'      => $body,
//         'timeout'   => 25,
//         'blocking'  => true,
//         'sslverify' => false,
//     ]);

//     // Debug file log on WordPress server
//     $log_file = WP_CONTENT_DIR . '/cf7_ats_result.log';
//     if (is_wp_error($response)) {
//         file_put_contents($log_file, date('[Y-m-d H:i:s] ') . 'ERROR: ' . $response->get_error_message() . "\n", FILE_APPEND);
//     } else {
//         $code = wp_remote_retrieve_response_code($response);
//         $res_body = wp_remote_retrieve_body($response);
//         file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "STATUS: {$code} | BODY: {$res_body}\n", FILE_APPEND);
//     }
// }

// /**
//  * Captures Contact Form 7 submission before mail is sent to preserve all field tags and context.
//  */
// add_action('wpcf7_before_send_mail', 'push_cf7_data_to_external_api', 20, 3);

// function push_cf7_data_to_external_api($contact_form, &$abort, $submission)
// {
//     if (!$submission) {
//         $submission = WPCF7_Submission::get_instance();
//     }

//     if ($submission) {
//         $posted_data = $submission->get_posted_data();

//         // --- FILE UPLOAD (RESUME) HANDLING ---
//         $uploaded_files = $submission->uploaded_files();
//         $resume_public_url = '';

//         if (!empty($uploaded_files['file-527'])) {
//             $temp_file_path = $uploaded_files['file-527'][0];

//             $wp_upload_dir = wp_upload_dir();
//             $target_dir = $wp_upload_dir['basedir'] . '/job_resumes';
//             $target_url = $wp_upload_dir['baseurl'] . '/job_resumes';

//             if (!file_exists($target_dir)) {
//                 wp_mkdir_p($target_dir);
//             }

//             $file_name = time() . '_' . basename($temp_file_path);
//             $final_target_path = $target_dir . '/' . $file_name;

//             if (copy($temp_file_path, $final_target_path)) {
//                 $resume_public_url = $target_url . '/' . $file_name;
//             }
//         }

//         // --- SANITIZE FORM FIELDS ---
//         $name         = !empty($posted_data['Name']) ? sanitize_text_field(is_array($posted_data['Name']) ? $posted_data['Name'][0] : $posted_data['Name']) : '';
//         $email        = !empty($posted_data['Email']) ? sanitize_email(is_array($posted_data['Email']) ? $posted_data['Email'][0] : $posted_data['Email']) : '';
//         $phone        = !empty($posted_data['Phone-Number']) ? sanitize_text_field(is_array($posted_data['Phone-Number']) ? $posted_data['Phone-Number'][0] : $posted_data['Phone-Number']) : '';
//         $location     = !empty($posted_data['Location']) ? sanitize_text_field(is_array($posted_data['Location']) ? $posted_data['Location'][0] : $posted_data['Location']) : '';
//         $organisation = !empty($posted_data['Current-Organisation']) ? sanitize_text_field(is_array($posted_data['Current-Organisation']) ? $posted_data['Current-Organisation'][0] : $posted_data['Current-Organisation']) : '';
//         $role         = !empty($posted_data['Current-Role']) ? sanitize_text_field(is_array($posted_data['Current-Role']) ? $posted_data['Current-Role'][0] : $posted_data['Current-Role']) : '';
//         $message      = !empty($posted_data['Message']) ? sanitize_textarea_field(is_array($posted_data['Message']) ? $posted_data['Message'][0] : $posted_data['Message']) : '';

//         $can_commute_raw = isset($posted_data['Are-you-able-commute-job']) ? $posted_data['Are-you-able-commute-job'] : '';
//         $can_commute  = !empty($can_commute_raw) ? (is_array($can_commute_raw) ? sanitize_text_field($can_commute_raw[0]) : sanitize_text_field($can_commute_raw)) : 'N/A';

//         // --- PAGE URL FETCHING (KEY FOR PORTAL POSITION MAPPING) ---
//         $page_url = '';
//         if (!empty($_SERVER['HTTP_REFERER'])) {
//             $page_url = strtok(esc_url_raw($_SERVER['HTTP_REFERER']), '?');
//         }

//         // --- JOB TITLE & POSITION ID RESOLUTION ---
//         $job_title = '';
//         $position_id = 0;

//         if (!empty($posted_data['job_title'])) {
//             $job_title = is_array($posted_data['job_title']) ? sanitize_text_field($posted_data['job_title'][0]) : sanitize_text_field($posted_data['job_title']);
//         }
//         if (!empty($posted_data['job_id'])) {
//             $position_id = intval(is_array($posted_data['job_id']) ? $posted_data['job_id'][0] : $posted_data['job_id']);
//         }

//         if (empty(trim($job_title)) || empty($position_id)) {
//             $resolved_post_id = 0;

//             $resolved_post_id = $submission->get_meta('container_post_id');

//             if (!$resolved_post_id && isset($_POST['_wpcf7_container_post'])) {
//                 $resolved_post_id = intval($_POST['_wpcf7_container_post']);
//             }

//             if (!$resolved_post_id && !empty($page_url)) {
//                 $resolved_post_id = url_to_postid($page_url);
//             }

//             if ($resolved_post_id) {
//                 if (empty($position_id)) {
//                     $position_id = $resolved_post_id;
//                 }
//                 if (empty(trim($job_title))) {
//                     $job_title = get_the_title($resolved_post_id);
//                 }
//             }
//         }

//         if (empty(trim($job_title))) {
//             $job_title = 'General Application';
//         }

//         $clean_job_title = html_entity_decode($job_title, ENT_QUOTES | ENT_HTML5, 'UTF-8');

//         // UTM tracking fields capture
//         $utm_source   = !empty($posted_data['utm_source']) ? sanitize_text_field(is_array($posted_data['utm_source']) ? $posted_data['utm_source'][0] : $posted_data['utm_source']) : '';
//         $utm_medium   = !empty($posted_data['utm_medium']) ? sanitize_text_field(is_array($posted_data['utm_medium']) ? $posted_data['utm_medium'][0] : $posted_data['utm_medium']) : '';
//         $utm_campaign = !empty($posted_data['utm_campaign']) ? sanitize_text_field(is_array($posted_data['utm_campaign']) ? $posted_data['utm_campaign'][0] : $posted_data['utm_campaign']) : '';

//         // Prepare JSON payload
//         $api_payload = array(
//             'position_id'          => $position_id,
//             'page_url'             => $page_url,
//             'job_title'            => $clean_job_title,
//             'name'                 => $name,
//             'email'                => $email,
//             'phone_number'         => $phone,
//             'location'             => $location,
//             'able_commute_job'     => $can_commute,
//             'current_organisation' => $organisation,
//             'current_role'         => $role,
//             'message'              => $message,
//             'resume_url'           => $resume_public_url,
//             'utm_source'           => $utm_source,
//             'utm_medium'           => $utm_medium,
//             'utm_campaign'         => $utm_campaign
//         );

//         // Target API URL (Live Yii2 Portal Endpoint)
//         $target_api_url = 'https://app.selectionsearch.in/backend/web/receive-application.php';

//         // Execute API POST Request with SSL bypass and 15s timeout
//         $response = wp_remote_post($target_api_url, array(
//             'method'      => 'POST',
//             'timeout'     => 15,
//             'blocking'    => true,
//             'sslverify'   => false,
//             'headers'     => array(
//                 'Content-Type' => 'application/json',
//             ),
//             'body'        => wp_json_encode($api_payload),
//         ));

//         // Direct File Log in wp-content/ to verify instant status
//         $log_file = WP_CONTENT_DIR . '/cf7_api_debug.log';
//         if (is_wp_error($response)) {
//             file_put_contents($log_file, date('[Y-m-d H:i:s] ') . 'WP_ERROR: ' . $response->get_error_message() . PHP_EOL, FILE_APPEND);
//         } else {
//             $code = wp_remote_retrieve_response_code($response);
//             $body = wp_remote_retrieve_body($response);
//             file_put_contents($log_file, date('[Y-m-d H:i:s] ') . "HTTP {$code}: " . $body . PHP_EOL, FILE_APPEND);
//         }
//     }
// }