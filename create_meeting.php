<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require 'vendor/autoload.php';

use Aws\Chime\ChimeClient;
use Ramsey\Uuid\Uuid;

// AWS kimlik bilgileri
$credentials = [
    'region'  => 'eu-central-1',
    'version' => 'latest',
    'credentials' => [
        'key'    => '',
        'secret' => ''
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $data = json_decode($json);

    if (!$data || !isset($data->roomName) || empty(trim($data->roomName))) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Geçerli bir oda adı gerekli',
            'details' => [
                'received' => $data->roomName ?? null,
                'required' => 'non-empty string'
            ]
        ]);
        exit;
    }

    // Oda adını temizle ve güvenli hale getir
    $roomName = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($data->roomName));
    
    if (empty($roomName)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Oda adı sadece harf, rakam, tire ve alt çizgi içerebilir',
            'details' => [
                'received' => $data->roomName,
                'sanitized' => $roomName
            ]
        ]);
        exit;
    }

    try {
        $chime = new ChimeClient([
            'region'  => 'eu-central-1',
            'version' => 'latest',
            'credentials' => [
                'key'    => $credentials['credentials']['key'],
                'secret' => $credentials['credentials']['secret']
            ],
            'endpoint' => 'https://meetings-chime.eu-central-1.amazonaws.com'
        ]);

        // Toplantı verilerini saklamak için dosya
        $meetingsFile = 'meetings.json';
        $meetings = [];
        
        if (file_exists($meetingsFile)) {
            $meetings = json_decode(file_get_contents($meetingsFile), true) ?: [];
        }

        $meeting = null;
        $shouldCreateNewMeeting = true;

        // Mevcut toplantıyı kontrol et
        if (isset($meetings[$roomName])) {
            try {
                // Toplantının hala aktif olup olmadığını kontrol et
                $getMeetingResult = $chime->getMeeting([
                    'MeetingId' => $meetings[$roomName]['MeetingId']
                ]);
                $meeting = $getMeetingResult['Meeting'];
                $shouldCreateNewMeeting = false;
                
            } catch (Exception $e) {
                // Toplantı artık mevcut değil, yeni bir tane oluşturacağız
                unset($meetings[$roomName]);
            }
        }

        // Eğer gerekiyorsa yeni toplantı oluştur
        if ($shouldCreateNewMeeting) {
            $createMeetingResult = $chime->createMeeting([
                'ClientRequestToken' => Uuid::uuid4()->toString(),
                'MediaRegion' => 'eu-central-1',
                'ExternalMeetingId' => $roomName,
                'MeetingFeatures' => [
                    'Audio' => [
                        'EchoReduction' => 'ENABLED'
                    ]
                ]
            ]);
            
            $meeting = $createMeetingResult['Meeting'];
            
            // Toplantıyı kaydet
            $meetings[$roomName] = [
                'MeetingId' => $meeting['MeetingId'],
                'MediaPlacement' => $meeting['MediaPlacement'],
                'CreatedAt' => time()
            ];
            
            file_put_contents($meetingsFile, json_encode($meetings));
        }

        // Katılımcı oluştur
        $attendee = $chime->createAttendee([
            'MeetingId' => $meeting['MeetingId'],
            'ExternalUserId' => Uuid::uuid4()->toString(),
            'Capabilities' => [
                'Audio' => 'SendReceive',
                'Video' => 'SendReceive',
                'Content' => 'SendReceive'
            ]
        ]);

        // Başarılı yanıt döndür
        echo json_encode([
            'Meeting' => $meeting,
            'Attendee' => $attendee['Attendee'],
            'RoomName' => $roomName
        ]);

    } catch (Exception $e) {
        // AWS bağlantı hatası
        http_response_code(500);
        echo json_encode([
            'error' => 'AWS bağlantı hatası: ' . $e->getMessage(),
            'details' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'type' => get_class($e),
                'roomName' => $roomName
            ]
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
 
