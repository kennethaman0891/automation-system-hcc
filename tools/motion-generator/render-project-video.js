/**
 * HCC Automation System — Whole Project Motion Graphics Video Renderer
 * 
 * Frame-accurately seeks the GSAP master animation timeline in Google Chrome
 * via Puppeteer, pipes uncompressed JPEG frames directly into FFmpeg stdin,
 * and muxes the synthesized 60.0-second stereo audio soundtrack into a
 * pristine broadcast-grade 1080p Full HD MP4 video.
 */

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const puppeteer = require('../../automation/node_modules/puppeteer');

const htmlPath = path.resolve(__dirname, 'project-motion-promo.html');
const audioPath = path.resolve(__dirname, 'project-soundtrack.wav');
const outVideoPath = path.resolve(__dirname, '../../assets/videos/hcc-project-promo.mp4');
const outPosterPath = path.resolve(__dirname, '../../assets/images/hcc-project-promo-poster.jpg');

const FPS = 30;
const DURATION = 60.0;
const TOTAL_FRAMES = Math.round(DURATION * FPS); // 1800 frames
const WIDTH = 1920;
const HEIGHT = 1080;

async function render() {
  console.log(`🎬 Starting HCC Whole Project Motion Graphics Video Render...`);
  console.log(`   Dimensions : ${WIDTH}x${HEIGHT} (1080p Full HD)`);
  console.log(`   Framerate  : ${FPS} fps`);
  console.log(`   Duration   : ${DURATION}s (${TOTAL_FRAMES} total frames)`);
  console.log(`   Input HTML : ${htmlPath}`);
  console.log(`   Soundtrack : ${audioPath}`);
  console.log(`   Target MP4 : ${outVideoPath}`);
  console.log(`   Poster     : ${outPosterPath}\n`);

  if (!fs.existsSync(audioPath)) {
    throw new Error(`Soundtrack not found at ${audioPath}. Please run project-soundtrack.js first.`);
  }

  // Ensure assets/videos directory exists
  const outDir = path.dirname(outVideoPath);
  if (!fs.existsSync(outDir)) {
    fs.mkdirSync(outDir, { recursive: true });
  }

  const browser = await puppeteer.launch({
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    headless: 'new',
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
  console.log('Navigating to motion graphics canvas...');
  await page.goto(`file://${htmlPath}?render`, { waitUntil: 'networkidle0' });

  // Verify GSAP timeline is loaded
  await page.waitForFunction(() => !!window.projectPromoTimeline, { timeout: 15000 });
  await page.evaluate(() => {
    window.projectPromoTimeline.pause();
    window.projectPromoTimeline.time(0);
  });

  console.log('✅ GSAP Master Timeline verified and paused at t=0.0s.');
  console.log('Spawning FFmpeg muxer process...');

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
    const str = data.toString();
    if (str.includes('Error') || str.includes('fatal')) {
      console.error('FFmpeg stderr:', str);
    }
  });

  const ffmpegPromise = new Promise((resolve, reject) => {
    ffmpeg.on('close', code => {
      if (code === 0) resolve();
      else reject(new Error(`FFmpeg exited with error code ${code}`));
    });
    ffmpeg.on('error', reject);
  });

  const startTime = Date.now();
  let posterCaptured = false;

  console.log(`Starting frame extraction (${TOTAL_FRAMES} frames)...`);

  for (let frame = 0; frame < TOTAL_FRAMES; frame++) {
    const t = frame / FPS;

    // Seek timeline to exact frame time
    await page.evaluate(time => {
      window.projectPromoTimeline.time(time);
    }, t);

    // Capture frame buffer
    const buffer = await page.screenshot({
      type: 'jpeg',
      quality: 95
    });

    // Save poster frame at t=57.0s (frame 1710, showing the Grand Finale Ecosystem lockup)
    if (frame === 1710 && !posterCaptured) {
      fs.writeFileSync(outPosterPath, buffer);
      posterCaptured = true;
      console.log(`📸 High-res poster frame saved at t=${t.toFixed(2)}s -> ${outPosterPath}`);
    }

    // Write to FFmpeg stdin with backpressure handling
    const canWrite = ffmpeg.stdin.write(buffer);
    if (!canWrite) {
      await new Promise(r => ffmpeg.stdin.once('drain', r));
    }

    // Log progress every 90 frames (every 3 seconds of video)
    if (frame % 90 === 0 || frame === TOTAL_FRAMES - 1) {
      const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);
      const pct = Math.round(((frame + 1) / TOTAL_FRAMES) * 100);
      const fpsReal = ((frame + 1) / ((Date.now() - startTime) / 1000)).toFixed(1);
      console.log(`Rendering: frame ${frame + 1}/${TOTAL_FRAMES} (${pct}%) — t=${t.toFixed(2)}s | ${elapsed}s elapsed (${fpsReal} render fps)`);
    }
  }

  // End input to FFmpeg
  ffmpeg.stdin.end();

  console.log('Finished streaming all 1800 frames. Finalizing FFmpeg encoding...');
  await ffmpegPromise;
  await browser.close();

  const totalTime = ((Date.now() - startTime) / 1000).toFixed(1);
  const stats = fs.statSync(outVideoPath);

  console.log(`\n🎉 SUCCESS! Broadcast Video Render Complete!`);
  console.log(`   Render Time : ${totalTime}s`);
  console.log(`   Output Video: ${outVideoPath}`);
  console.log(`   File Size   : ${(stats.size / 1024 / 1024).toFixed(2)} MB`);
  console.log(`   Poster Image: ${outPosterPath}`);
}

render().catch(err => {
  console.error('❌ Render failed:', err);
  process.exit(1);
});
