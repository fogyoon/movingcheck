<?php
require_once 'sql.inc';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// GPS 좌표 변환 함수
function convertGPSCoordinate($coordinate, $hemisphere) {
    if (!is_array($coordinate) || count($coordinate) < 3) {
        return null;
    }
    
    $degrees = convertRational($coordinate[0]);
    $minutes = convertRational($coordinate[1]);  
    $seconds = convertRational($coordinate[2]);
    
    $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
    
    // 남반구나 서경인 경우 음수
    if ($hemisphere == 'S' || $hemisphere == 'W') {
        $decimal = -$decimal;
    }
    
    return $decimal;
}

// GPS 고도 변환 함수
function convertGPSAltitude($altitude, $altitudeRef) {
    $alt = convertRational($altitude);
    
    // altitudeRef가 1이면 해수면 아래
    if ($altitudeRef == 1) {
        $alt = -$alt;
    }
    
    return $alt;
}

// EXIF 분수값을 소수로 변환
function convertRational($rational) {
    if (strpos($rational, '/') !== false) {
        $parts = explode('/', $rational);
        if (count($parts) == 2 && $parts[1] != 0) {
            return $parts[0] / $parts[1];
        }
    }
    return floatval($rational);
}

// 메타데이터 추출 함수 (강화된 버전)
function extractMetadata($filePath) {
    $metadata = [];
    
    // 기본 정보
    $metadata['file_size'] = filesize($filePath);
    $metadata['captured_at'] = date('Y-m-d H:i:s');
    
    try {
        // EXIF 데이터 추출 (모든 섹션 읽기)
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($filePath, 0, true);
            if ($exif) {
                
                // 촬영 날짜 (더 정확한 순서로)
                if (isset($exif['EXIF']['DateTimeOriginal'])) {
                    $metadata['captured_at'] = $exif['EXIF']['DateTimeOriginal'];
                } elseif (isset($exif['IFD0']['DateTime'])) {
                    $metadata['captured_at'] = $exif['IFD0']['DateTime'];
                } elseif (isset($exif['DateTime'])) {
                    $metadata['captured_at'] = $exif['DateTime'];
                }
                
                // GPS 정보 처리 (더 정확한 방법)
                if (isset($exif['GPS']) && !empty($exif['GPS'])) {
                    $metadata['gps_raw'] = $exif['GPS'];
                    
                    // GPS 좌표 계산
                    if (isset($exif['GPS']['GPSLatitude']) && isset($exif['GPS']['GPSLongitude'])) {
                        $lat = convertGPSCoordinate($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLatitudeRef'] ?? 'N');
                        $lon = convertGPSCoordinate($exif['GPS']['GPSLongitude'], $exif['GPS']['GPSLongitudeRef'] ?? 'E');
                        
                        if ($lat !== null && $lon !== null) {
                            $metadata['latitude'] = $lat;
                            $metadata['longitude'] = $lon;
                            $metadata['gps_formatted'] = sprintf("%.6f, %.6f", $lat, $lon);
                        }
                    }
                    
                    // 고도 정보
                    if (isset($exif['GPS']['GPSAltitude'])) {
                        $metadata['altitude'] = convertGPSAltitude($exif['GPS']['GPSAltitude'], $exif['GPS']['GPSAltitudeRef'] ?? 0);
                    }
                    
                    // GPS 시간
                    if (isset($exif['GPS']['GPSDateStamp'])) {
                        $metadata['gps_date'] = $exif['GPS']['GPSDateStamp'];
                    }
                }
                
                // 카메라 정보 (여러 위치에서 검색)
                if (isset($exif['IFD0']['Make'])) {
                    $metadata['camera_make'] = trim($exif['IFD0']['Make']);
                } elseif (isset($exif['Make'])) {
                    $metadata['camera_make'] = trim($exif['Make']);
                }
                
                if (isset($exif['IFD0']['Model'])) {
                    $metadata['camera_model'] = trim($exif['IFD0']['Model']);
                } elseif (isset($exif['Model'])) {
                    $metadata['camera_model'] = trim($exif['Model']);
                }
                
                if (isset($exif['IFD0']['Software'])) {
                    $metadata['software'] = trim($exif['IFD0']['Software']);
                } elseif (isset($exif['Software'])) {
                    $metadata['software'] = trim($exif['Software']);
                }
                
                // 이미지 크기
                if (isset($exif['COMPUTED']['Width'])) {
                    $metadata['width'] = $exif['COMPUTED']['Width'];
                }
                if (isset($exif['COMPUTED']['Height'])) {
                    $metadata['height'] = $exif['COMPUTED']['Height'];
                }
                
                // 카메라 설정
                if (isset($exif['EXIF']['FNumber'])) {
                    $metadata['f_number'] = convertRational($exif['EXIF']['FNumber']);
                }
                if (isset($exif['EXIF']['ExposureTime'])) {
                    $metadata['exposure_time'] = $exif['EXIF']['ExposureTime'];
                }
                if (isset($exif['EXIF']['ISOSpeedRatings'])) {
                    $metadata['iso'] = $exif['EXIF']['ISOSpeedRatings'];
                }
                if (isset($exif['EXIF']['FocalLength'])) {
                    $metadata['focal_length'] = convertRational($exif['EXIF']['FocalLength']);
                }
                
                // 방향 정보
                if (isset($exif['IFD0']['Orientation'])) {
                    $metadata['orientation'] = $exif['IFD0']['Orientation'];
                }
                
                // 전체 EXIF 정보를 디버깅용으로 저장
                $metadata['exif_debug'] = array_keys($exif);
            }
        }
        
        // getimagesize로 추가 정보 추출
        $imageInfo = @getimagesize($filePath);
        if ($imageInfo) {
            if (!isset($metadata['width'])) $metadata['width'] = $imageInfo[0];
            if (!isset($metadata['height'])) $metadata['height'] = $imageInfo[1];
            $metadata['mime_type'] = $imageInfo['mime'];
            
            // 추가 이미지 정보
            if (isset($imageInfo['channels'])) {
                $metadata['channels'] = $imageInfo['channels'];
            }
            if (isset($imageInfo['bits'])) {
                $metadata['bits'] = $imageInfo['bits'];
            }
        }
        
        // 파일의 실제 확장자 확인
        $metadata['file_extension'] = pathinfo($filePath, PATHINFO_EXTENSION);
        
    } catch (Exception $e) {
        // EXIF 추출 실패해도 계속 진행
        $metadata['exif_error'] = $e->getMessage();
    }
    
    return json_encode($metadata, JSON_UNESCAPED_UNICODE);
}
$pdo = get_pdo();
$user_id = $_SESSION['user_id'];
$contract_id = (int)($_GET['contract_id'] ?? 0);
if (!$contract_id) {
    die('잘못된 접근입니다.');
}
// 계약 정보
$stmt = $pdo->prepare('SELECT c.*, p.address FROM contracts c JOIN properties p ON c.property_id = p.id WHERE c.id = ?');
$stmt->execute([$contract_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contract) die('계약을 찾을 수 없습니다.');

// 기존 photo 정보 (사진 다시 등록 시)
$photo_id = (int)($_GET['photo_id'] ?? 0);
$existing_photo = null;
if ($photo_id) {
    $stmt = $pdo->prepare('SELECT * FROM photos WHERE id = ? AND contract_id = ?');
    $stmt->execute([$photo_id, $contract_id]);
    $existing_photo = $stmt->fetch(PDO::FETCH_ASSOC);
}

// purpose 자동 결정(입주/퇴거/수리)
$status = $contract['status'];
if (strpos($status, 'movein') !== false) $purpose = 'movein';
elseif (strpos($status, 'moveout') !== false) $purpose = 'moveout';
elseif (strpos($status, 'repair') !== false) $purpose = 'repair';
else $purpose = 'other';

$msg = '';
$msg_type = '';
// 사진 등록 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $part = trim($_POST['part'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $overview_file = $_FILES['overview'] ?? null;
    $closeup_files = $_FILES['closeup'] ?? null;
    $removed_photos = json_decode($_POST['removed_photos'] ?? '[]', true) ?: [];
    $movein_file_paths = [];
    $movein_shot_types = [];
    $movein_meta_datas = [];
    $moveout_file_paths = [];
    $moveout_shot_types = [];
    $moveout_meta_datas = [];
    // 사진 등록 불가 상태 체크
    if ($status === 'finished') {
        $msg = '계약이 완료된 상태에서는 사진을 등록할 수 없습니다.';
        $msg_type = 'error';
    } else {
        // 업로드 목적 결정 (입주/퇴거)
        $is_movein = ($status === 'empty' || $status === 'movein_photo' || $status === 'movein_landlord_signed');
        $is_moveout = ($status === 'movein_tenant_signed' || $status === 'moveout_photo' || $status === 'moveout_landlord_signed');
        // overview(위치확인) 1장
        if ($overview_file && $overview_file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($overview_file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png'];
            if (in_array($ext, $allowed)) {
                $fname = sprintf('photo_%d_%s_%s.%s', $contract_id, uniqid(), date('YmdHis'), $ext);
                $fpath = 'photos/' . $fname;
                
                // 이미지 크기 조정 및 압축 적용
                if (!resize_and_compress_image($overview_file['tmp_name'], $fpath)) {
                    // 크기 조정 실패 시 원본 파일 그대로 복사
                    move_uploaded_file($overview_file['tmp_name'], $fpath);
                }
                
                // 메타데이터 추출
                $metadata = extractMetadata($fpath);
                
                if ($is_movein) {
                    $movein_file_paths[] = $fpath;
                    $movein_shot_types[] = 'overview';
                    $movein_meta_datas[] = $metadata;
                } elseif ($is_moveout) {
                    $moveout_file_paths[] = $fpath;
                    $moveout_shot_types[] = 'overview';
                    $moveout_meta_datas[] = $metadata;
                }
            }
        }
        // closeup(세부) 1~5장
        if ($closeup_files && isset($closeup_files['name']) && is_array($closeup_files['name'])) {
            for ($i=0; $i<5; $i++) {
                if (!empty($closeup_files['name'][$i]) && $closeup_files['error'][$i] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($closeup_files['name'][$i], PATHINFO_EXTENSION));
                    $allowed = ['jpg','jpeg','png'];
                    if (in_array($ext, $allowed)) {
                        $fname = sprintf('photo_%d_%s_%s_%d.%s', $contract_id, uniqid(), date('YmdHis'), $i+1, $ext);
                        $fpath = 'photos/' . $fname;
                        
                        // 이미지 크기 조정 및 압축 적용
                        if (!resize_and_compress_image($closeup_files['tmp_name'][$i], $fpath)) {
                            // 크기 조정 실패 시 원본 파일 그대로 복사
                            move_uploaded_file($closeup_files['tmp_name'][$i], $fpath);
                        }
                        
                        // 메타데이터 추출
                        $metadata = extractMetadata($fpath);
                        
                        if ($is_movein) {
                            $movein_file_paths[] = $fpath;
                            $movein_shot_types[] = 'closeup';
                            $movein_meta_datas[] = $metadata;
                        } elseif ($is_moveout) {
                            $moveout_file_paths[] = $fpath;
                            $moveout_shot_types[] = 'closeup';
                            $moveout_meta_datas[] = $metadata;
                        }
                    }
                }
            }
        }
        // DB 저장 전에 동일 부위(위치) 중복 체크 (기존 photo 수정이 아닌 경우만)
        $duplicate_part_error = false;
        if (!$existing_photo) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM photos WHERE contract_id = ? AND part = ?');
            $stmt->execute([$contract_id, $part]);
            if ($stmt->fetchColumn() > 0) {
                $msg = '이미 동일한 부위(위치)로 등록된 사진이 있습니다.';
                $msg_type = 'error';
                $duplicate_part_error = true;
            }
        }
        
        // 동일 부위 오류가 없는 경우에만 계속 처리
        if (!$duplicate_part_error) {
            // 기존 사진이 있는 경우 새 사진이 없어도 처리 가능
            $has_new_photos = ($is_movein && $movein_file_paths) || ($is_moveout && $moveout_file_paths);
            $can_proceed = $has_new_photos || $existing_photo;
            
            if (($msg_type !== 'error') && $can_proceed) {
            if ($existing_photo) {
                // 기존 photo 업데이트
                $sql = "UPDATE photos SET part = ?, description = ?
                    , movein_shot_type_01 = ?, movein_file_path_01 = ?, movein_meta_data_01 = ?
                    , movein_shot_type_02 = ?, movein_file_path_02 = ?, movein_meta_data_02 = ?
                    , movein_shot_type_03 = ?, movein_file_path_03 = ?, movein_meta_data_03 = ?
                    , movein_shot_type_04 = ?, movein_file_path_04 = ?, movein_meta_data_04 = ?
                    , movein_shot_type_05 = ?, movein_file_path_05 = ?, movein_meta_data_05 = ?
                    , movein_shot_type_06 = ?, movein_file_path_06 = ?, movein_meta_data_06 = ?
                    , moveout_shot_type_01 = ?, moveout_file_path_01 = ?, moveout_meta_data_01 = ?
                    , moveout_shot_type_02 = ?, moveout_file_path_02 = ?, moveout_meta_data_02 = ?
                    , moveout_shot_type_03 = ?, moveout_file_path_03 = ?, moveout_meta_data_03 = ?
                    , moveout_shot_type_04 = ?, moveout_file_path_04 = ?, moveout_meta_data_04 = ?
                    , moveout_shot_type_05 = ?, moveout_file_path_05 = ?, moveout_meta_data_05 = ?
                    , moveout_shot_type_06 = ?, moveout_file_path_06 = ?, moveout_meta_data_06 = ?
                    , taken_at = ?, uploader_ip = ? WHERE id = ?";
                $params = [$part, $description];
            } else {
                // 새 photo 등록
                $sql = "INSERT INTO photos (contract_id, uploader_id, part, description
                    , movein_shot_type_01, movein_file_path_01, movein_meta_data_01
                    , movein_shot_type_02, movein_file_path_02, movein_meta_data_02
                    , movein_shot_type_03, movein_file_path_03, movein_meta_data_03
                    , movein_shot_type_04, movein_file_path_04, movein_meta_data_04
                    , movein_shot_type_05, movein_file_path_05, movein_meta_data_05
                    , movein_shot_type_06, movein_file_path_06, movein_meta_data_06
                    , moveout_shot_type_01, moveout_file_path_01, moveout_meta_data_01
                    , moveout_shot_type_02, moveout_file_path_02, moveout_meta_data_02
                    , moveout_shot_type_03, moveout_file_path_03, moveout_meta_data_03
                    , moveout_shot_type_04, moveout_file_path_04, moveout_meta_data_04
                    , moveout_shot_type_05, moveout_file_path_05, moveout_meta_data_05
                    , moveout_shot_type_06, moveout_file_path_06, moveout_meta_data_06
                    , taken_at, uploader_ip)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $params = [$contract_id, $user_id, $part, $description];
            }
            // movein 6개 (기존 사진 + 새 사진 병합)
            $merged_movein_types = [];
            $merged_movein_paths = [];
            $merged_movein_metas = [];
            
            if ($existing_photo) {
                // 기존 사진 중 삭제되지 않은 것들 추가
                for ($i=1; $i<=6; $i++) {
                    $existing_path = $existing_photo['movein_file_path_0' . $i] ?? null;
                    if ($existing_path && !in_array($existing_path, $removed_photos)) {
                        $merged_movein_types[] = $existing_photo['movein_shot_type_0' . $i] ?? null;
                        $merged_movein_paths[] = $existing_path;
                        $merged_movein_metas[] = $existing_photo['movein_meta_data_0' . $i] ?? null;
                    }
                }
            }
            
            // 새 사진 추가
            for ($i=0; $i<count($movein_file_paths); $i++) {
                if (count($merged_movein_paths) < 6) {
                    $merged_movein_types[] = $movein_shot_types[$i] ?? null;
                    $merged_movein_paths[] = $movein_file_paths[$i] ?? null;
                    $merged_movein_metas[] = $movein_meta_datas[$i] ?? null;
                }
            }
            
            // 6개 슬롯에 맞춰 파라미터 추가
            for ($i=0; $i<6; $i++) {
                $params[] = $merged_movein_types[$i] ?? null;
                $params[] = $merged_movein_paths[$i] ?? null;
                $params[] = $merged_movein_metas[$i] ?? null;
            }
            // moveout 6개 (기존 사진 + 새 사진 병합)
            $merged_moveout_types = [];
            $merged_moveout_paths = [];
            $merged_moveout_metas = [];
            
            if ($existing_photo) {
                // 기존 사진 중 삭제되지 않은 것들 추가
                for ($i=1; $i<=6; $i++) {
                    $existing_path = $existing_photo['moveout_file_path_0' . $i] ?? null;
                    if ($existing_path && !in_array($existing_path, $removed_photos)) {
                        $merged_moveout_types[] = $existing_photo['moveout_shot_type_0' . $i] ?? null;
                        $merged_moveout_paths[] = $existing_path;
                        $merged_moveout_metas[] = $existing_photo['moveout_meta_data_0' . $i] ?? null;
                    }
                }
            }
            
            // 새 사진 추가
            for ($i=0; $i<count($moveout_file_paths); $i++) {
                if (count($merged_moveout_paths) < 6) {
                    $merged_moveout_types[] = $moveout_shot_types[$i] ?? null;
                    $merged_moveout_paths[] = $moveout_file_paths[$i] ?? null;
                    $merged_moveout_metas[] = $moveout_meta_datas[$i] ?? null;
                }
            }
            
            // 6개 슬롯에 맞춰 파라미터 추가
            for ($i=0; $i<6; $i++) {
                $params[] = $merged_moveout_types[$i] ?? null;
                $params[] = $merged_moveout_paths[$i] ?? null;
                $params[] = $merged_moveout_metas[$i] ?? null;
            }
            $params[] = date('Y-m-d H:i:s');
            $params[] = $_SERVER['REMOTE_ADDR'] ?? '';
            
            // UPDATE 쿼리인 경우 마지막에 photo_id 추가
            if ($existing_photo) {
                $params[] = $existing_photo['id'];
            }

            // 디버깅 코드 제거됨

            $stmt = $pdo->prepare($sql);
            if ($stmt->execute($params)) {
                // 삭제된 기존 사진 파일들을 실제로 삭제
                if (!empty($removed_photos)) {
                    foreach ($removed_photos as $file_path) {
                        if (file_exists($file_path)) {
                            if (unlink($file_path)) {
                                error_log("삭제된 파일: " . $file_path);
                            } else {
                                error_log("파일 삭제 실패: " . $file_path);
                            }
                        }
                    }
                }
                
                // 사진 등록 성공 후 status 변경 및 signatures 삭제
                if ($status === 'empty') {
                    $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?')->execute(['movein_photo', $contract_id]);
                } elseif ($status === 'movein_landlord_signed') {
                    // status를 movein_photo로 변경
                    $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?')->execute(['movein_photo', $contract_id]);
                    
                    // 기존 signatures 데이터를 조회하여 파일 경로 확보
                    $sig_stmt = $pdo->prepare('SELECT signature_data FROM signatures WHERE contract_id = ? AND purpose = ? AND signer_role = ?');
                    $sig_stmt->execute([$contract_id, 'movein', 'landlord']);
                    $signature_files = $sig_stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // signatures 테이블에서 데이터 삭제
                    $pdo->prepare('DELETE FROM signatures WHERE contract_id = ? AND purpose = ? AND signer_role = ?')->execute([$contract_id, 'movein', 'landlord']);
                    
                    // 실제 signature 파일들 삭제
                    foreach ($signature_files as $signature_file) {
                        if ($signature_file && file_exists($signature_file)) {
                            if (unlink($signature_file)) {
                                error_log("삭제된 서명 파일: " . $signature_file);
                            } else {
                                error_log("서명 파일 삭제 실패: " . $signature_file);
                            }
                        }
                    }
                } elseif ($status === 'movein_tenant_signed') {
                    $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?')->execute(['moveout_photo', $contract_id]);
                } elseif ($status === 'moveout_landlord_signed') {
                    // status를 moveout_photo로 변경
                    $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?')->execute(['moveout_photo', $contract_id]);
                    
                    // 기존 signatures 데이터를 조회하여 파일 경로 확보
                    $sig_stmt = $pdo->prepare('SELECT signature_data FROM signatures WHERE contract_id = ? AND purpose = ? AND signer_role = ?');
                    $sig_stmt->execute([$contract_id, 'moveout', 'landlord']);
                    $signature_files = $sig_stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // signatures 테이블에서 데이터 삭제
                    $pdo->prepare('DELETE FROM signatures WHERE contract_id = ? AND purpose = ? AND signer_role = ?')->execute([$contract_id, 'moveout', 'landlord']);
                    
                    // 실제 signature 파일들 삭제
                    foreach ($signature_files as $signature_file) {
                        if ($signature_file && file_exists($signature_file)) {
                            if (unlink($signature_file)) {
                                error_log("삭제된 서명 파일: " . $signature_file);
                            } else {
                                error_log("서명 파일 삭제 실패: " . $signature_file);
                            }
                        }
                    }
                }
                
                // 활동 로그 기록
                $action_desc = $existing_photo ? '사진 수정' : '사진 업로드';
                log_user_activity($user_id, 'upload_photo', $action_desc . ' (부위: ' . $part . ', 계약 ID: ' . $contract_id . ')', $contract_id);
                
                // 나머지는 상태 유지
                header('Location: photo_list.php?contract_id=' . $contract_id);
                exit;
            }
            $msg = '사진이 등록되었습니다!';
            $msg_type = 'success';
        } else {
            $msg = '사진을 1장 이상 업로드 해주세요.';
            $msg_type = 'error';
        }
        } // !$duplicate_part_error 블록 닫기
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>사진 등록 - <?php echo htmlspecialchars($contract['address']); ?> - 무빙체크</title>
  <link rel="stylesheet" href="style.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
  <style>
    .edit-container, .photo-upload-form { max-width: 600px; margin: 2rem auto; background: #fff; border-radius: 14px; box-shadow: 0 4px 18px rgba(0,100,255,0.07); padding: 2.2rem 1.5rem; }
    .edit-title { font-size: 1.3rem; font-weight: 700; color: #1976d2; margin-bottom: 1.5rem; text-align: center; }
    .edit-form-group { margin-bottom: 1.4rem; }
    .edit-label { display: block; font-size: 1.05rem; font-weight: 600; color: #333; margin-bottom: 0.5rem; }
    .edit-input, .edit-textarea { width: 100%; padding: 0.8rem; border: 1.5px solid #e3eaf2; border-radius: 8px; font-size: 1rem; box-sizing: border-box; }
    .edit-input:focus, .edit-textarea:focus { outline: none; border-color: #0064FF; }
    .edit-textarea { min-height: 90px; resize: vertical; }
    .edit-btn { background: #0064FF; color: #fff; border: none; border-radius: 8px; padding: 0.9rem 2.2rem; font-size: 1.1rem; font-weight: 700; cursor: pointer; width: 100%; margin-top: 0.5rem; transition: background 0.15s; }
    .edit-btn:hover { background: #0052cc; }
    .edit-msg { text-align: center; margin-bottom: 1.2rem; font-size: 1.05rem; border-radius: 8px; padding: 0.7rem; }
    .edit-msg.success { background: #e3fcec; color: #197d4c; border: 1px solid #b2f2d7; }
    .edit-msg.error { background: #fff0f0; color: #d32f2f; border: 1px solid #ffcdd2; }
    .photo-upload-form h2 { font-size: 1.3rem; font-weight: 700; margin-bottom: 1.2rem; }
    .photo-upload-form label, .photo-upload-form input, .photo-upload-form textarea {
      display: block;
      width: 100%;
      text-align: left;
      margin-left: 0;
      margin-right: 0;
      box-sizing: border-box;
    }
    .photo-upload-form label {
      margin-bottom: 0.6rem;
      margin-top: 1.2rem;
    }
    .photo-upload-form input,
    .photo-upload-form textarea {
      margin-bottom: 1.1rem;
    }
    .photo-upload-form .btn { margin-top: 1.2rem; }
    .photo-upload-form .desc { color: #888; font-size: 0.97em; margin-bottom: 0.7rem; }
    .file-upload-area { border: 2px dashed #b3c6e0; border-radius: 14px; background: #f4f8ff; min-height: 140px; display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: pointer; margin-bottom: 1rem; position: relative; transition: border-color 0.2s; padding: 1.5rem 1rem; }
    .file-upload-area.dragover { border-color: #1976d2; background: #e3f0ff; }
    .file-upload-icon { font-size: 2.5rem; margin-bottom: 0.5rem; color: #1976d2; }
    .file-upload-text { font-size: 1.1rem; color: #1976d2; margin-bottom: 0.3rem; }
    .file-upload-hint { color: #888; font-size: 0.97em; margin-bottom: 0.5rem; }
    .thumb-list { display: flex; flex-wrap: wrap; gap: 0.7rem; margin-top: 0.5rem; }
    .thumb-item { position: relative; width: 90px; height: 90px; border-radius: 10px; overflow: hidden; background: #e9ecef; display: flex; align-items: center; justify-content: center; }
    .thumb-item img { width: 100%; height: 100%; object-fit: cover; }
    .thumb-remove { position: absolute; top: 2px; right: 2px; background: rgba(0,0,0,0.5); color: #fff; border: none; border-radius: 50%; width: 22px; height: 22px; font-size: 1.1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 2; }
    .thumb-placeholder { width: 90px; height: 90px; border-radius: 10px; background: #f4f8ff; display: flex; align-items: center; justify-content: center; color: #b3c6e0; font-size: 2.2rem; border: 1.5px dashed #b3c6e0; }
    .photo-list { margin: 2rem auto; max-width: 700px; }
    .photo-list h3 { font-size: 1.1rem; margin-bottom: 1rem; }
    .photo-list-item { background: #f8f9fa; border-radius: 10px; padding: 1rem; margin-bottom: 1.2rem; display: flex; gap: 1.2rem; align-items: flex-start; }
    .photo-list-item img { width: 120px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,100,255,0.06); }
    .photo-list-item .photo-info { flex: 1; }
    .photo-list-item .photo-info .part { font-weight: 700; color: #1976d2; }
    .photo-list-item .photo-info .desc { color: #555; margin: 0.3rem 0 0.7rem 0; }
    @media (max-width:600px) { .photo-upload-form, .photo-list { padding: 1rem; } .photo-list-item { flex-direction: column; align-items: stretch; } .photo-list-item img { width: 100%; max-width: 95vw; } .thumb-list { gap: 0.4rem; } .thumb-item, .thumb-placeholder { width: 70px; height: 70px; font-size: 1.5rem; } }
  </style>
</head>
<body>
<?php include 'header.inc'; ?>
<main>
  <div class="photo-upload-form">
    <h2><?php echo $existing_photo ? '사진 다시 등록' : '사진 등록'; ?></h2>
    <?php if ($existing_photo): ?>
      <div class="edit-msg" style="background: #e3f0ff; color: #1976d2; border: 1px solid #b3c6e0;">
        ■ 기존 등록된 사진을 수정합니다: <?php echo htmlspecialchars($existing_photo['part']); ?>
      </div>
    <?php endif; ?>
    <?php if ($msg): ?>
      <div class="edit-msg <?php echo $msg_type; ?>">■ <?php echo $msg; ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" id="photoForm">
      <div class="edit-form-group">
        <label class="edit-label">부위(위치) <span style="color:#d32f2f;">*</span></label>
        <input type="text" name="part" class="edit-input" required placeholder="예: 거실 벽, 욕실 세면대 등" value="<?php echo htmlspecialchars($existing_photo['part'] ?? ''); ?>">
      </div>
      <div class="edit-form-group">
        <label class="edit-label">설명</label>
        <textarea name="description" class="edit-textarea" rows="2" placeholder="특이사항, 상태 등"><?php echo htmlspecialchars($existing_photo['description'] ?? ''); ?></textarea>
      </div>

      <div class="desc">위치확인용(전체) 사진 1장, 세부(근접) 사진 1~5장 업로드</div>
      <div class="edit-form-group">
        <label class="edit-label">위치확인용(전체) 사진 <span style="color:#d32f2f;">*</span></label>
        <div class="file-upload-area" id="overviewUploadArea" style="margin-bottom:1rem;">
          <div class="file-upload-text" id="overviewUploadText">사진을 선택하거나 촬영하세요</div>
          <div class="file-upload-hint">JPG, PNG 파일 지원 (최대 1장, 5MB)</div>
          <input type="file" name="overview" id="overviewInput" accept="image/*" style="display:none;">
          <div class="thumb-list" id="overviewThumbList">
            <div class="thumb-placeholder" id="overviewPlaceholder">+</div>
          </div>
        </div>
      </div>
      <div class="edit-form-group">
        <label class="edit-label">세부(근접) 사진 (최대 5장) <span style="color:#d32f2f;">*</span></label>
        <div class="file-upload-area" id="closeupUploadArea">
          <div class="file-upload-text" id="closeupUploadText">사진을 선택하거나 촬영하세요 (여러장 가능)</div>
          <div class="file-upload-hint">JPG, PNG 파일 지원 (최대 5장, 각 5MB)</div>
          <input type="file" name="closeup[]" id="closeupInput" accept="image/*" multiple style="display:none;">
          <div class="thumb-list" id="closeupThumbList">
            <div class="thumb-placeholder" id="closeupPlaceholder">+</div>
          </div>
        </div>
      </div>
      <div style="display:flex; gap:0.7rem; margin-top:1.2rem;">
        <button type="submit" class="edit-btn" style="flex:1;"><?php echo $existing_photo ? '사진 수정' : '사진 등록'; ?></button>
        <a href="photo_list.php?contract_id=<?php echo $contract_id; ?>" class="edit-btn" style="background:#bbb; color:#222; text-align:center; text-decoration:none; flex:1;">취소</a>
      </div>
    </form>
  </div>
  <!-- 사진 목록은 photo_list.php에서 확인하세요. -->
</main>
<?php if ($msg_type === 'error' && $msg): ?>
<script>alert('<?php echo addslashes($msg); ?>');</script>
<?php endif; ?>
<script>
// overview 업로드 영역
const overviewArea = document.getElementById('overviewUploadArea');
const overviewInput = document.getElementById('overviewInput');
const overviewText = document.getElementById('overviewUploadText');
const overviewThumbList = document.getElementById('overviewThumbList');
const overviewPlaceholder = document.getElementById('overviewPlaceholder');

// closeup 업로드 영역 (누적 업로드 지원)
const closeupArea = document.getElementById('closeupUploadArea');
const closeupInput = document.getElementById('closeupInput');
const closeupText = document.getElementById('closeupUploadText');
const closeupThumbList = document.getElementById('closeupThumbList');
const closeupPlaceholder = document.getElementById('closeupPlaceholder');
let closeupFiles = [];

// 공통 함수들 (새 등록 및 수정 모드 모두에서 사용)
function updateCloseupInputFromArray() {
  const dt = new DataTransfer();
  closeupFiles.forEach(f => dt.items.add(f));
  closeupInput.files = dt.files;
}

// overview 썸네일 렌더링 함수 (새 등록 모드용)
function renderOverviewThumbs() {
  overviewThumbList.innerHTML = '';
  
  <?php if ($existing_photo): ?>
  // 수정 모드: 기존 사진 + 새 사진 처리
  // 기존 overview 사진 표시 (삭제되지 않은 경우)
  if (existingOverviewPhoto && !removedExistingPhotos.includes(existingOverviewPhoto.path)) {
    const thumbItem = document.createElement('div');
    thumbItem.className = 'thumb-item';
    const img = document.createElement('img');
    img.src = existingOverviewPhoto.path;
    img.style.border = '2px solid #28a745'; // 기존 사진임을 표시
    thumbItem.appendChild(img);
    
    const removeBtn = document.createElement('button');
    removeBtn.className = 'thumb-remove';
    removeBtn.type = 'button';
    removeBtn.innerHTML = '&times;';
    removeBtn.onclick = function() {
      // 기존 사진을 삭제 목록에 추가
      removedExistingPhotos.push(existingOverviewPhoto.path);
      renderOverviewThumbs();
      updateOverviewText();
    };
    thumbItem.appendChild(removeBtn);
    overviewThumbList.appendChild(thumbItem);
  }
  <?php endif; ?>
  
  // 새로 선택한 overview 사진 표시
  if (overviewInput.files && overviewInput.files.length > 0) {
    const file = overviewInput.files[0];
    const reader = new FileReader();
    const thumbItem = document.createElement('div');
    thumbItem.className = 'thumb-item';
    const img = document.createElement('img');
    thumbItem.appendChild(img);
    const removeBtn = document.createElement('button');
    removeBtn.className = 'thumb-remove';
    removeBtn.type = 'button';
    removeBtn.innerHTML = '&times;';
    removeBtn.onclick = function() {
      overviewInput.value = '';
      renderOverviewThumbs();
      updateOverviewText();
    };
    thumbItem.appendChild(removeBtn);
    reader.onload = e => { img.src = e.target.result; };
    reader.readAsDataURL(file);
    overviewThumbList.appendChild(thumbItem);
  }
  
  // placeholder 추가 (사진이 없는 경우)
  let hasOverview;
  <?php if ($existing_photo): ?>
  hasOverview = (existingOverviewPhoto && !removedExistingPhotos.includes(existingOverviewPhoto.path)) || 
                (overviewInput.files && overviewInput.files.length > 0);
  <?php else: ?>
  hasOverview = overviewInput.files && overviewInput.files.length > 0;
  <?php endif; ?>
  if (!hasOverview) {
    const placeholder = document.createElement('div');
    placeholder.className = 'thumb-placeholder';
    placeholder.textContent = '+';
    overviewThumbList.appendChild(placeholder);
  }
}

// closeup 썸네일 렌더링 함수 (새 등록 모드용)
function renderCloseupThumbs() {
  closeupThumbList.innerHTML = '';
  
  <?php if ($existing_photo): ?>
  // 수정 모드: 기존 closeup 사진들 표시 (삭제되지 않은 것들만)
  existingCloseupPhotos.forEach((photo, index) => {
    if (!removedExistingPhotos.includes(photo.path)) {
      const thumbItem = document.createElement('div');
      thumbItem.className = 'thumb-item';
      const img = document.createElement('img');
      img.src = photo.path;
      img.style.border = '2px solid #28a745'; // 기존 사진임을 표시
      thumbItem.appendChild(img);
      
      const removeBtn = document.createElement('button');
      removeBtn.className = 'thumb-remove';
      removeBtn.type = 'button';
      removeBtn.innerHTML = '&times;';
      removeBtn.onclick = function() {
        // 기존 사진을 삭제 목록에 추가
        removedExistingPhotos.push(photo.path);
        renderCloseupThumbs();
        updateCloseupText();
      };
      thumbItem.appendChild(removeBtn);
      closeupThumbList.appendChild(thumbItem);
    }
  });
  <?php endif; ?>
  
  // 새로 선택한 closeup 사진들 표시
  closeupFiles.forEach((file, i) => {
    const reader = new FileReader();
    const thumbItem = document.createElement('div');
    thumbItem.className = 'thumb-item';
    const img = document.createElement('img');
    thumbItem.appendChild(img);
    const removeBtn = document.createElement('button');
    removeBtn.className = 'thumb-remove';
    removeBtn.type = 'button';
    removeBtn.innerHTML = '&times;';
    removeBtn.onclick = function() {
      closeupFiles.splice(i, 1);
      updateCloseupInputFromArray();
      renderCloseupThumbs();
      updateCloseupText();
    };
    thumbItem.appendChild(removeBtn);
    reader.onload = e => { img.src = e.target.result; };
    reader.readAsDataURL(file);
    closeupThumbList.appendChild(thumbItem);
  });
  
  // placeholder 추가 (전체 사진이 5개 미만인 경우)
  let totalCount;
  <?php if ($existing_photo): ?>
  const existingCount = existingCloseupPhotos.filter(p => !removedExistingPhotos.includes(p.path)).length;
  totalCount = existingCount + closeupFiles.length;
  <?php else: ?>
  totalCount = closeupFiles.length;
  <?php endif; ?>
  if (totalCount < 5) {
    const placeholder = document.createElement('div');
    placeholder.className = 'thumb-placeholder';
    placeholder.textContent = '+';
    closeupThumbList.appendChild(placeholder);
  }
}

// overview 텍스트 업데이트 함수
function updateOverviewText() {
  <?php if ($existing_photo): ?>
  const hasExistingOverview = existingOverviewPhoto && !removedExistingPhotos.includes(existingOverviewPhoto.path);
  const hasNewOverview = overviewInput.files && overviewInput.files.length > 0;
  
  if (hasNewOverview) {
    overviewText.textContent = overviewInput.files[0].name;
  } else if (hasExistingOverview) {
    overviewText.textContent = '기존 위치확인 사진';
  } else {
    overviewText.textContent = '사진을 선택하거나 촬영하세요';
  }
  <?php else: ?>
  if (overviewInput.files && overviewInput.files.length > 0) {
    overviewText.textContent = overviewInput.files[0].name;
  } else {
    overviewText.textContent = '사진을 선택하거나 촬영하세요';
  }
  <?php endif; ?>
}

// closeup 텍스트 업데이트 함수
function updateCloseupText() {
  <?php if ($existing_photo): ?>
  const existingCount = existingCloseupPhotos.filter(p => !removedExistingPhotos.includes(p.path)).length;
  const newCount = closeupFiles.length;
  const totalCount = existingCount + newCount;
  
  if (newCount > 0) {
    const names = closeupFiles.map(f => f.name);
    closeupText.textContent = `새 사진 ${newCount}장: ${names.join(', ')}`;
  } else if (existingCount > 0) {
    closeupText.textContent = `기존 세부사진 ${existingCount}장`;
  } else {
    closeupText.textContent = '사진을 선택하거나 촬영하세요 (여러장 가능)';
  }
  <?php else: ?>
  if (closeupFiles.length > 0) {
    const names = closeupFiles.map(f => f.name);
    closeupText.textContent = `${closeupFiles.length}장 선택됨: ${names.join(', ')}`;
  } else {
    closeupText.textContent = '사진을 선택하거나 촬영하세요 (여러장 가능)';
  }
  <?php endif; ?>
}

// 이벤트 리스너 설정
overviewArea.addEventListener('click', () => {
  // 위치확인용 사진 제한 체크 (최대 1장)
  <?php if ($existing_photo): ?>
  const hasExistingOverview = existingOverviewPhoto && !removedExistingPhotos.includes(existingOverviewPhoto.path);
  const hasNewOverview = overviewInput.files && overviewInput.files.length > 0;
  
  if (hasExistingOverview || hasNewOverview) {
    alert('사진은 1장까지 등록 가능합니다. 다른 사진을 추가하려면 등록된 사진을 지워야 합니다.');
    return;
  }
  <?php else: ?>
  if (overviewInput.files && overviewInput.files.length > 0) {
    alert('사진은 1장까지 등록 가능합니다. 다른 사진을 추가하려면 등록된 사진을 지워야 합니다.');
    return;
  }
  <?php endif; ?>
  
  overviewInput.click();
});
overviewInput.addEventListener('change', function() {
  renderOverviewThumbs();
  updateOverviewText();
});
overviewArea.addEventListener('dragover', e => { e.preventDefault(); overviewArea.classList.add('dragover'); });
overviewArea.addEventListener('dragleave', () => overviewArea.classList.remove('dragover'));
overviewArea.addEventListener('drop', e => {
  e.preventDefault(); overviewArea.classList.remove('dragover');
  
  // 위치확인용 사진 제한 체크 (최대 1장)
  <?php if ($existing_photo): ?>
  const hasExistingOverview = existingOverviewPhoto && !removedExistingPhotos.includes(existingOverviewPhoto.path);
  const hasNewOverview = overviewInput.files && overviewInput.files.length > 0;
  
  if (hasExistingOverview || hasNewOverview) {
    alert('사진은 1장까지 등록 가능합니다. 다른 사진을 추가하려면 등록된 사진을 지워야 합니다.');
    return;
  }
  <?php else: ?>
  if (overviewInput.files && overviewInput.files.length > 0) {
    alert('사진은 1장까지 등록 가능합니다. 다른 사진을 추가하려면 등록된 사진을 지워야 합니다.');
    return;
  }
  <?php endif; ?>
  
  if (e.dataTransfer.files.length > 0) {
    overviewInput.files = e.dataTransfer.files;
    renderOverviewThumbs();
    updateOverviewText();
  }
});

closeupArea.addEventListener('click', () => {
  // 세부사진 제한 체크 (최대 5장)
  <?php if ($existing_photo): ?>
  const existingCloseupCount = existingCloseupPhotos.filter(p => !removedExistingPhotos.includes(p.path)).length;
  const newCloseupCount = closeupFiles.length;
  const totalCloseupCount = existingCloseupCount + newCloseupCount;
  
  if (totalCloseupCount >= 5) {
    alert('사진은 5장까지 등록 가능합니다. 다른 사진을 추가하려면 등록된 사진을 지워야 합니다.');
    return;
  }
  <?php else: ?>
  if (closeupFiles.length >= 5) {
    alert('사진은 5장까지 등록 가능합니다. 다른 사진을 추가하려면 등록된 사진을 지워야 합니다.');
    return;
  }
  <?php endif; ?>
  
  closeupInput.click();
});
closeupInput.addEventListener('change', function(e) {
  // 누적: 기존 closeupFiles + 새로 선택한 파일
  if (this.files.length > 0) {
    let newFiles = Array.from(this.files);
    // 기존 사진 수 + 새 사진 수 계산
    let currentTotal;
    <?php if ($existing_photo): ?>
    const existingCount = existingCloseupPhotos.filter(p => !removedExistingPhotos.includes(p.path)).length;
    currentTotal = existingCount + closeupFiles.length;
    <?php else: ?>
    currentTotal = closeupFiles.length;
    <?php endif; ?>
    
    // 최대 5장 제한
    if (currentTotal >= 5) {
      alert('사진은 5장까지 등록 가능합니다. 다른 사진을 추가하려면 등록된 사진을 지워야 합니다.');
      this.value = '';
      return;
    }
    
    let total = currentTotal + newFiles.length;
    if (total > 5) {
      newFiles = newFiles.slice(0, 5 - currentTotal);
      alert('사진은 5장까지 등록 가능합니다. 다른 사진을 추가하려면 등록된 사진을 지워야 합니다.');
    }
    
    closeupFiles = closeupFiles.concat(newFiles);
    updateCloseupInputFromArray();
    renderCloseupThumbs();
    updateCloseupText();
  }
  // input을 리셋해서 같은 파일도 다시 선택 가능하게
  this.value = '';
});
closeupArea.addEventListener('dragover', e => { e.preventDefault(); closeupArea.classList.add('dragover'); });
closeupArea.addEventListener('dragleave', () => closeupArea.classList.remove('dragover'));
closeupArea.addEventListener('drop', e => {
  e.preventDefault(); closeupArea.classList.remove('dragover');
  let newFiles = Array.from(e.dataTransfer.files);
  let currentTotalDrop;
  <?php if ($existing_photo): ?>
  const existingCountDrop = existingCloseupPhotos.filter(p => !removedExistingPhotos.includes(p.path)).length;
  currentTotalDrop = existingCountDrop + closeupFiles.length;
  <?php else: ?>
  currentTotalDrop = closeupFiles.length;
  <?php endif; ?>
  
  if (currentTotalDrop >= 5) {
    alert('사진은 5장까지 등록 가능합니다. 다른 사진을 추가하려면 등록된 사진을 지워야 합니다.');
    return;
  }
  
  let total = currentTotalDrop + newFiles.length;
  if (total > 5) {
    newFiles = newFiles.slice(0, 5 - currentTotalDrop);
    alert('사진은 5장까지 등록 가능합니다. 다른 사진을 추가하려면 등록된 사진을 지워야 합니다.');
  }
  
  closeupFiles = closeupFiles.concat(newFiles);
  updateCloseupInputFromArray();
  renderCloseupThumbs();
  updateCloseupText();
});
// 폼 제출 시 closeupFiles를 input.files에 반영
const photoForm = document.getElementById('photoForm');
photoForm.addEventListener('submit', function(e) {
  updateCloseupInputFromArray();
  
  <?php if ($existing_photo): ?>
  // 기존 사진이 있는 경우: 삭제되지 않은 기존 사진 + 새 사진 검사
  const hasExistingOverview = existingOverviewPhoto && !removedExistingPhotos.includes(existingOverviewPhoto.path);
  const hasNewOverview = overviewInput.files && overviewInput.files.length > 0;
  const existingCloseupCount = existingCloseupPhotos.filter(p => !removedExistingPhotos.includes(p.path)).length;
  const hasNewCloseup = closeupFiles.length > 0;
  
  if (!hasExistingOverview && !hasNewOverview) {
    alert('위치확인용(전체) 사진을 1장 등록해야 합니다.');
    e.preventDefault();
    return false;
  }
  if (existingCloseupCount === 0 && !hasNewCloseup) {
    alert('세부(근접) 사진을 1장 이상 등록해야 합니다.');
    e.preventDefault();
    return false;
  }
  <?php else: ?>
  // 새 사진 등록인 경우: 반드시 새 사진 필요
  if (!overviewInput.files || overviewInput.files.length === 0) {
    alert('위치확인용(전체) 사진을 1장 등록해야 합니다.');
    e.preventDefault();
    return false;
  }
  if (closeupFiles.length === 0) {
    alert('세부(근접) 사진을 1장 이상 등록해야 합니다.');
    e.preventDefault();
    return false;
  }
  <?php endif; ?>
});

// 기존 사진 로드 (사진 수정 모드인 경우)
<?php if ($existing_photo): ?>
// 기존 사진 데이터를 JavaScript 변수로 전달
const existingPhotos = {
  movein: [
    <?php
    for ($i = 1; $i <= 6; $i++) {
      $field_name = 'movein_file_path_0' . $i;
      $shot_type = 'movein_shot_type_0' . $i;
      if (!empty($existing_photo[$field_name])) {
        echo "{ path: '" . addslashes($existing_photo[$field_name]) . "', type: '" . addslashes($existing_photo[$shot_type] ?? '') . "' },\n";
      }
    }
    ?>
  ].filter(p => p.path),
  moveout: [
    <?php
    for ($i = 1; $i <= 6; $i++) {
      $field_name = 'moveout_file_path_0' . $i;
      $shot_type = 'moveout_shot_type_0' . $i;
      if (!empty($existing_photo[$field_name])) {
        echo "{ path: '" . addslashes($existing_photo[$field_name]) . "', type: '" . addslashes($existing_photo[$shot_type] ?? '') . "' },\n";
      }
    }
    ?>
  ].filter(p => p.path)
};

// 현재 상태에 따라 어떤 사진을 표시할지 결정
const currentStatus = '<?php echo $status; ?>';
let photosToShow = [];
if (currentStatus.includes('movein')) {
  photosToShow = existingPhotos.movein;
} else if (currentStatus.includes('moveout')) {
  photosToShow = existingPhotos.moveout;
}

// 기존 사진과 새 사진을 분리해서 관리
let existingOverviewPhoto = null;
let existingCloseupPhotos = [];
let removedExistingPhotos = []; // 삭제된 기존 사진 추적

// 기존 사진을 업로드 영역에 표시
function loadExistingPhotos() {
  if (photosToShow.length === 0) return;
  
  // overview 사진 찾기 (overview 타입만)
  existingOverviewPhoto = photosToShow.find(p => p.type === 'overview') || null;
  
  // closeup 사진들 (overview가 아닌 모든 사진 + overview가 없을 때는 첫 번째 사진 제외)
  if (existingOverviewPhoto) {
    existingCloseupPhotos = photosToShow.filter(p => p.type !== 'overview');
  } else {
    // overview 타입이 없으면 첫 번째 사진을 overview로 사용하고 나머지를 closeup으로
    if (photosToShow.length > 0) {
      existingOverviewPhoto = photosToShow[0];
      existingCloseupPhotos = photosToShow.slice(1);
    }
  }
  
  renderAllThumbs();
}

// 모든 썸네일 다시 렌더링
function renderAllThumbs() {
  renderOverviewThumbs();
  renderCloseupThumbs();
}

// 중복된 함수 정의 제거됨 (위에서 공통 함수로 정의)

// 중복된 텍스트 업데이트 함수들도 제거됨 (위에서 공통 함수로 정의)

// 페이지 로드 시 기존 사진 표시
loadExistingPhotos();

// 폼 제출 시 삭제된 사진 정보를 hidden input에 추가
photoForm.addEventListener('submit', function(e) {
  // 기존 submit 이벤트 처리 후 삭제된 사진 정보 추가
  const removedInput = document.createElement('input');
  removedInput.type = 'hidden';
  removedInput.name = 'removed_photos';
  removedInput.value = JSON.stringify(removedExistingPhotos);
  photoForm.appendChild(removedInput);
});
<?php endif; ?>

</script>
<?php include 'footer.inc'; ?>
</body>
</html> 