<?php
require_once 'sql.inc';

// JSON 응답을 위한 헤더 설정
header('Content-Type: application/json');

// 로그인 체크
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

// POST 요청 체크
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
    exit;
}

$contract_id = (int)($_POST['contract_id'] ?? 0);
$photo_id = (int)($_POST['photo_id'] ?? 0);
$type = $_POST['type'] ?? '';
$index = (int)($_POST['index'] ?? 0);
$photo_data = $_POST['photo_data'] ?? '';

// 필수 파라미터 체크
if (!$contract_id || !$photo_id || !$type || !$index || !$photo_data) {
    echo json_encode(['success' => false, 'message' => '필수 파라미터가 누락되었습니다.']);
    exit;
}

// 유효한 타입 체크
if (!in_array($type, ['overview', 'closeup'])) {
    echo json_encode(['success' => false, 'message' => '잘못된 사진 타입입니다.']);
    exit;
}

// 유효한 인덱스 체크
if ($index < 1 || $index > 6) {
    echo json_encode(['success' => false, 'message' => '잘못된 사진 인덱스입니다.']);
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
function extractMetadata($imageData, $filePath) {
    $metadata = [];
    
    // 기본 정보
    $metadata['file_size'] = strlen($imageData);
    $metadata['captured_at'] = date('Y-m-d H:i:s');
    
    // 임시 파일에 저장하여 EXIF 데이터 추출
    $tempFile = tempnam(sys_get_temp_dir(), 'moveout_');
    file_put_contents($tempFile, $imageData);
    
    try {
        // EXIF 데이터 추출 (모든 섹션 읽기)
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($tempFile, 0, true);
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
        $imageInfo = @getimagesize($tempFile);
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
    } finally {
        // 임시 파일 삭제
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }
    
    return json_encode($metadata, JSON_UNESCAPED_UNICODE);
}

try {
    $pdo = get_pdo();
    
    // 권한 체크: 해당 사진이 실제로 존재하는지 확인
    $stmt = $pdo->prepare('SELECT * FROM photos WHERE id = ? AND contract_id = ?');
    $stmt->execute([$photo_id, $contract_id]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$photo) {
        echo json_encode(['success' => false, 'message' => '사진을 찾을 수 없습니다.']);
        exit;
    }
    
    // Base64 데이터에서 이미지 데이터 추출
    if (strpos($photo_data, 'data:image/jpeg;base64,') === 0) {
        $image_data = base64_decode(substr($photo_data, strlen('data:image/jpeg;base64,')));
    } else {
        echo json_encode(['success' => false, 'message' => '잘못된 이미지 데이터 형식입니다.']);
        exit;
    }
    
    // 파일 저장 경로 생성
    $photos_dir = 'photos';
    if (!file_exists($photos_dir)) {
        mkdir($photos_dir, 0755, true);
    }
    
    // 파일명 생성 (moveout_contractId_photoId_type_index_timestamp.jpg)
    $timestamp = time();
    $filename = "moveout_{$contract_id}_{$photo_id}_{$type}_{$index}_{$timestamp}.jpg";
    $file_path = $photos_dir . '/' . $filename;
    
    // 이미지 크기 조정 및 압축 적용 (EXIF 회전 처리 건너뛰기 - JavaScript에서 이미 처리됨)
    if (!process_base64_image($photo_data, $file_path, true)) {
        // 크기 조정 실패 시 원본 데이터로 저장
        if (file_put_contents($file_path, $image_data) === false) {
            echo json_encode(['success' => false, 'message' => '파일 저장에 실패했습니다.']);
            exit;
        }
    }
    
    // 메타데이터 추출
    $metadata = extractMetadata($image_data, $file_path);
    
    // 기존 사진이 있는지 확인하고 삭제
    $index_padded = str_pad($index, 2, '0', STR_PAD_LEFT); // 01, 02, 03, ... 형태로 변환
    $file_field = "moveout_file_path_{$index_padded}";
    $type_field = "moveout_shot_type_{$index_padded}";
    $meta_field = "moveout_meta_data_{$index_padded}";
    
    // 기존 파일 경로 확인
    $existing_file_path = $photo[$file_field] ?? null;
    
    // 데이터베이스에 파일 경로, 타입, 메타데이터 저장
    $sql = "UPDATE photos SET {$file_field} = ?, {$type_field} = ?, {$meta_field} = ? WHERE id = ? AND contract_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$file_path, $type, $metadata, $photo_id, $contract_id]);
    
    // DB 업데이트 성공 후 기존 파일 삭제
    if ($stmt->rowCount() > 0 && $existing_file_path && file_exists($existing_file_path)) {
        unlink($existing_file_path);
    }
    
    if ($stmt->rowCount() === 0) {
        // 파일 삭제 (DB 업데이트 실패 시)
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        echo json_encode(['success' => false, 'message' => '데이터베이스 업데이트에 실패했습니다.']);
        exit;
    }
    
    // 계약 상태 확인 및 업데이트 (퇴거 사진 저장 시 moveout_photo로 변경)
    $stmt = $pdo->prepare('SELECT status FROM contracts WHERE id = ?');
    $stmt->execute([$contract_id]);
    $current_status = $stmt->fetchColumn();
    
    // 퇴거 사진이 저장되면 계약 상태를 moveout_photo로 변경
    if (in_array($current_status, ['movein_tenant_signed', 'moveout_landlord_signed'])) {
        $stmt = $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?');
        $stmt->execute(['moveout_photo', $contract_id]);
        
        // 활동 로그에 상태 변경 기록
        $log_message = '';
        if ($current_status === 'movein_tenant_signed') {
            $log_message = '퇴거 사진 촬영으로 인한 상태 변경: movein_tenant_signed -> moveout_photo';
        } elseif ($current_status === 'moveout_landlord_signed') {
            $log_message = '퇴거 사진 수정으로 인한 상태 변경: moveout_landlord_signed -> moveout_photo';
        }
        
        log_user_activity($_SESSION['user_id'], 'update_contract', $log_message . ' (계약 ID: ' . $contract_id . ')', $contract_id);
    } elseif ($current_status === 'moveout_tenant_signed') {
        // moveout_tenant_signed 상태에서는 퇴거 사진 촬영 불가
        echo json_encode(['success' => false, 'message' => '임차인 서명이 완료된 상태에서는 퇴거 사진을 수정할 수 없습니다.']);
        exit;
    }
    
    // 활동 로그 기록
    log_user_activity($_SESSION['user_id'], 'upload_photo', '퇴거 사진 저장 (타입: ' . $type . ', 인덱스: ' . $index . ', 계약 ID: ' . $contract_id . ')', $contract_id);
    
    echo json_encode([
        'success' => true,
        'message' => '사진이 성공적으로 저장되었습니다.',
        'file_path' => $file_path,
        'metadata' => json_decode($metadata, true)
    ]);
    
} catch (Exception $e) {
    // 오류 발생 시 생성된 파일 삭제
    if (isset($file_path) && file_exists($file_path)) {
        unlink($file_path);
    }
    
    echo json_encode([
        'success' => false,
        'message' => '저장 중 오류가 발생했습니다: ' . $e->getMessage()
    ]);
}
?> 