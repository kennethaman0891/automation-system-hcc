/**
 * HCC WhatsApp Hub — Frame-Accurate Video Renderer
 * Uses Google Chrome (via puppeteer-core) to seek the GSAP motion graphics timeline
 * frame by frame, pipe JPEG frames into FFmpeg, and mux the synthesized stereo soundtrack.
 */

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const puppeteer = require('/tmp/motion-renderer/node_modules/puppeteer-core');

const htmlPath = path.resolve(__dirname, 'wa-motion-promo.html');
const audioPath = path.resolve(__dirname, 'soundtrack.wav');
const outVideoPath = path.resolve(__dirname, '../../assets/videos/whatsapp-hub-promo.mp4');
const outPosterPath = path.resolve(__dirname, '../../assets/images/wa-promo-poster.jpg');

const FPS = 30;
const DURATION = 18.0;
const TOTAL_FRAMES = Math.round(DURATION * FPS); // 540 frames
const WIDTH = 1920;
const HEIGHT = 1080;

async function render() {
  console.log(`Starting Darkmotion Skills Video Render...`);
  console.log(`Target: ${WIDTH}x${HEIGHT} @ ${FPS}fps, Total frames: ${TOTAL_FRAMES} (${DURATION}s)`);
  console.log(`Input HTML: ${htmlPath}`);
  console.log(`Audio: ${audioPath}`);
  console.log(`Output: ${outVideoPath}`);

  const browser = await puppeteer.launch({
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-accelerated-2d-canvas',
      '--no-first-run',
      '--no-zygote',
      `--window-size=${WIDTH},${HEIGHT}`,
      '--force-device-scale-factor=1',
      '--hide-scrollbars'
    ],
    defaultViewport: {
      width: WIDTH,
      height: HEIGHT,
      deviceScaleFactor: 1
    }
  });

  const page = await browser.newPage();
  await page.goto(`file://${htmlPath}?render`, { waitUntil: 'networkidle0' });

  // Verify GSAP timeline is loaded
  await page.waitForFunction(() => !!window.promoTimeline, { timeout: 15000 });
  await page.evaluate(() => {
    window.promoTimeline.pause();
    window.promoTimeline.time(0);
  });

  console.log('GSAP Timeline paused and initialized. Spawning FFmpeg...');

  // Spawn FFmpeg to mux video pipe and audio soundtrack
  const ffmpegArgs = [
    '-y',
    '-f', 'image2pipe',
    '-vcodec', 'mjpeg',
    '-framerate', String(FPS),
    '-i', '-', // video from stdin
    '-i', audioPath, // audio from wav
    '-c:v', 'libx264',
    '-pix_fmt', 'yuv420p',
    '-preset', 'fast',
    '-crf', '18',
    '-c:a', 'aac',
    '-b:a', '192k',
    '-shortest',
    '-movflags', '+faststart',
    outVideoPath
  ];

  const ffmpeg = spawn('/opt/homebrew/bin/ffmpeg', ffmpegArgs);

  ffmpeg.stderr.on('data', data => {
    // Only log if error
    const str = data.toString();
    if (str.includes('Error') || str.includes('fatal')) {
      console.error('FFmpeg stderr:', str);
    }
  });

  let ffmpegExitCode = null;
  const ffmpegPromise = new Promise((resolve, reject) => {
    ffmpeg.on('close', code => {
      ffmpegExitCode = code;
      if (code === 0) resolve();
      else reject(new Error(`FFmpeg exited with error code ${code}`));
    });
    ffmpeg.on('error', reject);
  });

  // Track progress
  const startTime = Date.now();
  let posterCaptured = false;

  for (let frame = 0; frame < TOTAL_FRAMES; frame++) {
    const t = frame / FPS;

    // Seek timeline to exact frame time
    await page.evaluate(time => {
      window.promoTimeline.time(time);
    }, t);

    // Capture frame buffer
    const buffer = await page.screenshot({
      type: 'jpeg',
      quality: 95
    });

    // Save frame 270 (t=9.0s, during WhatsApp sent bubble & PDF delivery) as high-res poster
    if (frame === 270 && !posterCaptured) {
      fs.writeFileSync(outPosterPath, buffer);
      posterCaptured = true;
      console.log(`Captured high-res poster frame at t=${t.toFixed(2)}s -> ${outPosterPath}`);
    }

    // Write to FFmpeg stdin with backpressure handling
    const canWrite = ffmpeg.stdin.write(buffer);
    if (!canWrite) {
      await new Promise(r => ffmpeg.stdin.once('drain', r));
    }

    if (frame % 60 === 0 || frame === TOTAL_FRAMES - 1) {
      const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);
      const pct = Math.round(((frame + 1) / TOTAL_FRAMES) * 100);
      console.log(`Rendering: frame ${frame + 1}/${TOTAL_FRAMES} (${pct}%) — ${elapsed}s elapsed`);
    }
  }

  // End input to FFmpeg
  ffmpeg.stdin.end();

  console.log('Finished feeding frames. Waiting for FFmpeg to finish encoding...');
  await ffmpegPromise;
  await browser.close();

  const totalTime = ((Date.now() - startTime) / 1000).toFixed(1);
  const stats = fs.statSync(outVideoPath);
  console.log(`\n🎉 Success! Render completed in ${totalTime}s.`);
  console.log(`Video saved to: ${outVideoPath}`);
  console.log(`Size: ${(stats.size / 1024 / 1024).toFixed(2)} MB`);
}

render().catch(err => {
  console.error('Render failed:', err);
  process.exit(1);
});
