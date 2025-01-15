<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8" />
  <title>Amazon Chime SDK Toplantı</title>
  <!-- Chime JavaScript SDK (tek dosya) -->
  <script src="./amazon-chime-sdk.min.js"></script>
  <link rel="stylesheet" href="style.css">

</head>
<body>
<h1>Chime SDK Testi</h1>

<div id="roomInfo">
  <strong>Oda Bilgisi:</strong> <span id="roomName">Yükleniyor...</span>
</div>

<div class="controls">
  <button onclick="joinMeeting()">Toplantıya Katıl</button>
  <button onclick="toggleMute()">Mikrofon Aç/Kapat</button>
  <button onclick="toggleCamera()">Kamera Aç/Kapat</button>
  <button onclick="testAudio()">Ses Testi (Lokal)</button>
</div>

<!-- Videolar -->
<div class="video-container">
  <div class="video-wrapper">
    <video
      id="localVideo"
      autoplay
      muted
      playsinline
      style="width: 200px; border: 1px solid #ccc;"
    ></video>
    <div class="video-label">Yerel Video</div>
  </div>
  <div class="video-wrapper">
    <video
      id="remoteVideo"
      autoplay
      playsinline
      style="width: 200px; border: 1px solid #ccc;"
    ></video>
    <div class="video-label">Uzak Video</div>
  </div>
</div>

<div id="logs"></div>

<script>
  // ================ GLOBAL DEĞİŞKENLER ================
  const ChimeClient = {
    meetingSession: null,
    audioVideo: null,
    mediaStream: null,
    state: {
      isMuted: false,
      isCameraOff: false,
      currentRoomName: null
    },
    config: {
      video: {
        width: { ideal: 1280 },
        height: { ideal: 720 },
        facingMode: 'user',
        frameRate: { ideal: 30 }
      },
      audio: {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true
      }
    }
  };

  // ================ YARDIMCI FONKSİYONLAR ================
  const Utils = {
    log: function(message) {
      console.log(message);
      const logsDiv = document.getElementById('logs');
      logsDiv.innerHTML += message + '<br>';
      logsDiv.scrollTop = logsDiv.scrollHeight;
    },

    getUrlParams: function() {
      const params = new URLSearchParams(window.location.search);
      return {
        roomName: params.get('room_name')
      };
    }
  };

  // ================ MEDYA YÖNETİMİ ================
  const MediaManager = {
    async requestUserMedia() {
      try {
        // Önce ses izni al
        const audioStream = await navigator.mediaDevices.getUserMedia({
          audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
            sampleRate: 48000,
            channelCount: 1
          },
          video: false
        });

        // Sonra video izni al
        const videoStream = await navigator.mediaDevices.getUserMedia({
          audio: false,
          video: {
            width: { ideal: 1280 },
            height: { ideal: 720 },
            facingMode: 'user',
            frameRate: { ideal: 30 }
          }
        });

        // İki stream'i birleştir
        const audioTrack = audioStream.getAudioTracks()[0];
        const videoTrack = videoStream.getVideoTracks()[0];
        
        ChimeClient.mediaStream = new MediaStream([audioTrack, videoTrack]);

        // Ses ayarlarını kontrol et
        if (audioTrack) {
          const settings = audioTrack.getSettings();
          Utils.log('Ses ayarları: ' + JSON.stringify(settings));
        }

        Utils.log('Tarayıcıdan mikrofon/kamera izni alındı.');
        return true;
      } catch (error) {
        Utils.log('Kamera/Mikrofon isteği reddedildi: ' + error.message);
        return false;
      }
    },

    async setupAudioVideo() {
      const { audioVideo } = ChimeClient;
      try {
        // Önce mevcut akışları durdur
        try {
          await audioVideo.stopAudioInput();
          await audioVideo.stopVideoInput();
        } catch (stopError) {
          Utils.log('Mevcut akışları durdurma hatası (önemli değil): ' + stopError.message);
        }

        // Ses ve video girişlerini başlat
        try {
          // Ses girişi için cihaz ID'sini al
          const audioTrack = ChimeClient.mediaStream.getAudioTracks()[0];
          const audioSettings = audioTrack.getSettings();
          const audioDeviceId = audioSettings.deviceId;

          // Ses girişini başlat
          await audioVideo.chooseAudioInputDevice(audioDeviceId);
          Utils.log('Ses girişi başlatıldı');

          // Video girişini başlat
          await audioVideo.startVideoInput(ChimeClient.mediaStream);
          Utils.log('Video girişi başlatıldı');
        } catch (mediaError) {
          Utils.log('Medya başlatma hatası: ' + mediaError.message);
          return false;
        }

        // Video elementlerini ayarla
        const localVideo = document.getElementById('localVideo');
        const remoteVideo = document.getElementById('remoteVideo');
        
        localVideo.srcObject = ChimeClient.mediaStream;
        localVideo.muted = true;

        // Ses çıkışını ayarla
        try {
          await audioVideo.bindAudioElement(remoteVideo);
          Utils.log('Ses çıkışı bağlandı');
          remoteVideo.volume = 1.0;
          remoteVideo.muted = false;
        } catch (bindError) {
          Utils.log('Ses çıkışı bağlama hatası: ' + bindError.message);
          return false;
        }

        return true;
      } catch (error) {
        Utils.log('Ses/Video akışı başlatma hatası: ' + error.message);
        return false;
      }
    }
  };

  // ================ TOPLANTI YÖNETİMİ ================
  const MeetingManager = {
    async createMeetingSession(roomName) {
      try {
        const response = await fetch("https://chime-test.test/create_meeting.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ roomName })
        }).then(res => res.json());

        if (!response.Meeting || !response.Attendee) {
          throw new Error("Geçerli Meeting veya Attendee verisi yok.");
        }

        Utils.log('Toplantı verisi alındı');
        Utils.log('Meeting ID: ' + response.Meeting.MeetingId);
        Utils.log('Attendee ID: ' + response.Attendee.AttendeeId);

        return response;
      } catch (error) {
        Utils.log('Toplantı oluşturma hatası: ' + error.message);
        throw error;
      }
    },

    setupObservers() {
      const { audioVideo } = ChimeClient;
      
      audioVideo.addObserver({
        audioVideoDidStart: () => {
          Utils.log('Görüşme başladı');
          audioVideo.startLocalVideoTile();
        },
        audioVideoDidStop: (sessionStatus) => {
          Utils.log('Görüşme durdu: ' + sessionStatus.statusCode());
        },
        videoTileDidUpdate: (tileState) => {
          if (!tileState || !tileState.tileId) return;
          
          const videoElement = tileState.localTile ? 
            document.getElementById('localVideo') : 
            document.getElementById('remoteVideo');

          Utils.log(`Video tile güncellendi - ${tileState.localTile ? 'Yerel' : 'Uzak'}`);
          audioVideo.bindVideoElement(tileState.tileId, videoElement);
        },
        videoTileWasRemoved: (tileId) => {
          Utils.log(`Video tile kaldırıldı: ${tileId}`);
        },
        audioInputDidStart: () => {
          Utils.log('Ses girişi başladı');
        },
        audioInputDidStop: () => {
          Utils.log('Ses girişi durdu');
        },
        remoteAudioSourcesDidChange: (audioSources) => {
          Utils.log('Uzak ses kaynakları değişti: ' + JSON.stringify(audioSources));
          audioSources.forEach(source => {
            const attendeeId = source.attendee.attendeeId;
            audioVideo.realtimeSubscribeToVolumeIndicator(
              attendeeId,
              (attendeeId, volume, muted, signalStrength) => {
                if (volume > 0) {
                  Utils.log(`Uzak ses aktif - Attendee: ${attendeeId}, Volume: ${volume}, Muted: ${muted}`);
                }
              }
            );
          });
        }
      });

      // Katılımcı durumu izleme
      audioVideo.realtimeSubscribeToAttendeeIdPresence((attendeeId, present) => {
        Utils.log(`Katılımcı durumu değişti - ID: ${attendeeId}, Present: ${present}`);
      });
    }
  };

  // ================ KULLANICI ETKİLEŞİMLERİ ================
  async function joinMeeting() {
    if (!ChimeClient.state.currentRoomName) {
      Utils.log('HATA: Oda adı belirtilmemiş!');
      return;
    }

    try {
      // 1. Medya izinlerini al
      if (!await MediaManager.requestUserMedia()) return;

      // 2. Toplantı oturumunu oluştur
      const meetingData = await MeetingManager.createMeetingSession(ChimeClient.state.currentRoomName);
      
      // 3. Chime istemcisini yapılandır
      const logger = new ChimeSDK.ConsoleLogger('ChimeMeetingLogs', ChimeSDK.LogLevel.INFO);
      const configuration = new ChimeSDK.MeetingSessionConfiguration(meetingData.Meeting, meetingData.Attendee);
      const deviceController = new ChimeSDK.DefaultDeviceController(logger, { enableWebAudio: true });
      
      ChimeClient.meetingSession = new ChimeSDK.DefaultMeetingSession(configuration, logger, deviceController);
      ChimeClient.audioVideo = ChimeClient.meetingSession.audioVideo;

      // 4. Observer'ları ayarla
      MeetingManager.setupObservers();

      // 5. Medya akışlarını başlat
      if (!await MediaManager.setupAudioVideo()) {
        Utils.log('Medya akışları başlatılamadı');
        return;
      }

      // 6. Toplantıyı başlat
      try {
        await ChimeClient.audioVideo.start();
        Utils.log('Toplantı başarıyla başlatıldı');
      } catch (error) {
        Utils.log('Toplantı başlatma hatası: ' + error.message);
        return;
      }

    } catch (error) {
      Utils.log('HATA: ' + error.message);
      alert('Toplantıya bağlanırken hata oluştu: ' + error.message);
    }
  }

  async function toggleCamera() {
    if (!ChimeClient.audioVideo) {
      Utils.log('Kamera değiştirilemedi: audioVideo yok');
      return;
    }

    try {
      if (ChimeClient.state.isCameraOff) {
        const mediaStream = await navigator.mediaDevices.getUserMedia({
          video: ChimeClient.config.video
        });
        
        await ChimeClient.audioVideo.startVideoInput(mediaStream);
        ChimeClient.audioVideo.startLocalVideoTile();
        Utils.log('Kamera açıldı');
      } else {
        ChimeClient.audioVideo.stopLocalVideoTile();
        await ChimeClient.audioVideo.stopVideoInput();
        Utils.log('Kamera kapatıldı');
      }
      
      ChimeClient.state.isCameraOff = !ChimeClient.state.isCameraOff;
    } catch (error) {
      Utils.log('Kamera değiştirme hatası: ' + error.message);
    }
  }

  function toggleMute() {
    if (!ChimeClient.audioVideo) {
      Utils.log('Mikrofon değiştirilemedi: audioVideo yok');
      return;
    }

    ChimeClient.state.isMuted = !ChimeClient.state.isMuted;
    ChimeClient.audioVideo.realtimeMuteLocalAudio(ChimeClient.state.isMuted);
    Utils.log('Mikrofon ' + (ChimeClient.state.isMuted ? 'kapatıldı' : 'açıldı'));
  }

  async function testAudio() {
    try {
      const audioContext = new (window.AudioContext || window.webkitAudioContext)();
      const oscillator = audioContext.createOscillator();
      const gainNode = audioContext.createGain();
      
      oscillator.connect(gainNode);
      gainNode.connect(audioContext.destination);
      
      oscillator.frequency.value = 440;
      gainNode.gain.value = 0.1;
      
      oscillator.start();
      setTimeout(() => {
        oscillator.stop();
        Utils.log('Ses testi tamamlandı');
      }, 1000);
      
      Utils.log('Ses testi yapılıyor...');
    } catch (err) {
      Utils.log('Ses testi hatası: ' + err.message);
    }
  }

  // ================ SAYFA YÜKLENDİĞİNDE ================
  window.onload = function() {
    const params = Utils.getUrlParams();
    ChimeClient.state.currentRoomName = params.roomName;
    
    if (!ChimeClient.state.currentRoomName) {
      Utils.log('HATA: Oda adı belirtilmemiş!');
      document.getElementById('roomName').textContent = 'Oda adı belirtilmemiş!';
      return;
    }

    document.getElementById('roomName').textContent = ChimeClient.state.currentRoomName;
    Utils.log('Oda adı: ' + ChimeClient.state.currentRoomName);
  };

  // ================ SAYFA KAPATILDIĞINDA ================
  window.onbeforeunload = () => {
    if (ChimeClient.audioVideo) {
      ChimeClient.audioVideo.stop();
    }
  };
</script>
</body>
</html>
