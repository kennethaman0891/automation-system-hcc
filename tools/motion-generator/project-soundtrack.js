/**
 * HCC Automation System — Whole Project Motion Graphics Soundtrack Synthesizer
 * Generates a full 60.0-second 44.1kHz 16-bit PCM stereo WAV soundtrack
 * with warm ambient electronic chord progressions, rhythmic digital pulses,
 * and frame-accurate procedural sound effects tailored to the 11 subsystems:
 * - Sub-bass drop and intro chime (0.0s - 5.0s)
 * - Clock pulses, UI clicks, notification chirps (5.0s - 10.5s)
 * - Data arpeggios, digital stamp impact, verification chime (10.5s - 16.5s)
 * - Continuous evaluation quad-tone blips & laser sweep (16.5s - 21.5s)
 * - Timetable magnetic snap clicks & shutter pulse (21.5s - 27.0s)
 * - Promotion ascending riser & vault lock latch (27.0s - 32.5s)
 * - Bonafide parchment slide, wax seal stamp, QR ping (32.5s - 37.5s)
 * - Drive cloud upload swoosh & harmonic ripple (37.5s - 42.5s)
 * - WhatsApp sent pop, batch acceleration riser, read double-chime (42.5s - 49.5s)
 * - Admission AI neural flutter & staff approval lock (49.5s - 54.5s)
 * - Grand celebratory finale chords, bell arpeggios & tape stop (54.5s - 60.0s)
 */

const fs = require('fs');
const path = require('path');

const sampleRate = 44100;
const duration = 60.0;
const totalSamples = Math.floor(sampleRate * duration);
const numChannels = 2; // Stereo

const left = new Float32Array(totalSamples);
const right = new Float32Array(totalSamples);

// Helper: Add sine or warm composite wave with ADSR envelope
function addTone(freq, startSec, durSec, gain, pan = 0, type = 'sine', attack = 0.08, decay = 3.5) {
  const startSample = Math.floor(startSec * sampleRate);
  const endSample = Math.min(totalSamples, startSample + Math.floor(durSec * sampleRate));
  if (startSample >= totalSamples) return;

  const panL = Math.cos((pan + 1) * Math.PI / 4);
  const panR = Math.sin((pan + 1) * Math.PI / 4);

  for (let i = startSample; i < endSample; i++) {
    const t = (i - startSample) / sampleRate;
    const progress = t / durSec;

    let env = 1.0;
    if (progress < attack) {
      env = progress / attack;
    } else {
      env = Math.exp(-progress * decay);
    }

    let val = 0;
    if (type === 'sine') {
      val = Math.sin(2 * Math.PI * freq * t);
    } else if (type === 'warm') {
      val = 0.65 * Math.sin(2 * Math.PI * freq * t) +
            0.25 * Math.sin(4 * Math.PI * freq * t) +
            0.10 * Math.sin(6 * Math.PI * freq * t);
    } else if (type === 'sub') {
      val = Math.sin(2 * Math.PI * freq * t) + 0.3 * Math.sin(Math.PI * freq * t);
    } else if (type === 'noise') {
      val = (Math.random() * 2 - 1);
    }

    const sample = val * env * gain;
    left[i] += sample * panL;
    right[i] += sample * panR;
  }
}

// Helper: Frequency sweep (chirp / laser / riser)
function addSweep(startFreq, endFreq, startSec, durSec, gain, pan = 0, type = 'sine') {
  const startSample = Math.floor(startSec * sampleRate);
  const endSample = Math.min(totalSamples, startSample + Math.floor(durSec * sampleRate));
  if (startSample >= totalSamples) return;

  const panL = Math.cos((pan + 1) * Math.PI / 4);
  const panR = Math.sin((pan + 1) * Math.PI / 4);

  for (let i = startSample; i < endSample; i++) {
    const t = (i - startSample) / sampleRate;
    const progress = t / durSec;
    const currentFreq = startFreq + (endFreq - startFreq) * progress;
    const env = Math.sin(progress * Math.PI) * Math.exp(-progress * 2.0);
    const val = Math.sin(2 * Math.PI * currentFreq * t) * env * gain;

    left[i] += val * panL;
    right[i] += val * panR;
  }
}

// ════════════════════════════════════════════════════════════════════════════
// 1. HARMONIC SYNTH PAD CHORD PROGRESSION (60 SECONDS)
// ════════════════════════════════════════════════════════════════════════════
const movements = [
  // Scene 1: Am7 (0.0 - 5.0)
  { start: 0.0, dur: 5.2, notes: [220.00, 261.63, 329.63, 392.00] },
  // Scene 2: Em7 (5.0 - 10.5)
  { start: 5.0, dur: 5.7, notes: [164.81, 196.00, 246.94, 293.66, 392.00] },
  // Scene 3: Fmaj7 (10.5 - 16.5)
  { start: 10.5, dur: 6.2, notes: [174.61, 220.00, 261.63, 329.63, 440.00] },
  // Scene 4: Dm7 (16.5 - 21.5)
  { start: 16.5, dur: 5.2, notes: [146.83, 220.00, 261.63, 349.23, 440.00] },
  // Scene 5: Gmaj (21.5 - 27.0)
  { start: 21.5, dur: 5.7, notes: [196.00, 246.94, 293.66, 392.00, 493.88] },
  // Scene 6: Cmaj7 (27.0 - 32.5)
  { start: 27.0, dur: 5.7, notes: [130.81, 196.00, 261.63, 329.63, 493.88] },
  // Scene 7: Am7 (32.5 - 37.5)
  { start: 32.5, dur: 5.2, notes: [220.00, 261.63, 329.63, 392.00, 523.25] },
  // Scene 8: Fmaj9 (37.5 - 42.5)
  { start: 37.5, dur: 5.2, notes: [174.61, 220.00, 261.63, 329.63, 392.00] },
  // Scene 9: Dm7 -> G (42.5 - 49.5)
  { start: 42.5, dur: 3.5, notes: [146.83, 220.00, 261.63, 349.23] },
  { start: 46.0, dur: 3.7, notes: [196.00, 246.94, 293.66, 392.00] },
  // Scene 10: Em7 -> F (49.5 - 54.5)
  { start: 49.5, dur: 5.2, notes: [164.81, 220.00, 261.63, 329.63, 440.00] },
  // Scene 11: Grand Finale resolving into Cmaj and Am (54.5 - 60.0)
  { start: 54.5, dur: 5.5, notes: [261.63, 329.63, 392.00, 523.25, 659.25] }
];

movements.forEach(m => {
  m.notes.forEach((freq, idx) => {
    const pan = (idx % 2 === 0 ? -0.35 : 0.35);
    addTone(freq, m.start, m.dur, 0.055, pan, 'warm', 0.12, 1.8);
    // Sub-bass resonance for root note
    if (idx === 0) {
      addTone(freq * 0.5, m.start, m.dur, 0.045, 0, 'sub', 0.15, 1.5);
    }
  });
});

// ════════════════════════════════════════════════════════════════════════════
// 2. RHYTHMIC HIGH-TECH DIGITAL PULSES (TEMPO: 124 BPM = 0.4838s)
// ════════════════════════════════════════════════════════════════════════════
const beatDuration = 60 / 124; // ~0.484s
for (let t = 2.0; t < 59.0; t += beatDuration) {
  // Soft clock click
  const pan = (Math.round(t / beatDuration) % 2 === 0 ? -0.2 : 0.2);
  addTone(1800, t, 0.035, 0.012, pan, 'sine', 0.02, 6.0);
}

// ════════════════════════════════════════════════════════════════════════════
// 3. SCENE-SYNCHRONIZED SOUND DESIGN & SFX
// ════════════════════════════════════════════════════════════════════════════

// ── Scene 1: Intro Sub-Drop & Crystal Chime (0.0s - 5.0s)
addSweep(110, 45, 0.1, 1.8, 0.25, 0, 'sub'); // 55Hz deep drop
addTone(880, 0.8, 1.5, 0.12, 0.2, 'warm', 0.02, 3.0);
addTone(1320, 1.0, 1.8, 0.09, -0.2, 'warm', 0.02, 3.0);
addTone(1760, 1.2, 2.0, 0.08, 0, 'warm', 0.02, 3.0);

// ── Scene 2: Dashboard Cards Whoosh & Notification Alert (5.0s - 10.5s)
addSweep(200, 600, 5.2, 0.4, 0.10, -0.3, 'sine'); // Card slide
addTone(1400, 7.2, 0.08, 0.15, 0.2, 'warm'); // Notification chirp 1
addTone(2100, 7.3, 0.12, 0.12, 0.2, 'warm'); // Notification chirp 2
addSweep(400, 1200, 8.8, 0.35, 0.14, 0, 'sine'); // Star badge pop

// ── Scene 3: Examination Grid Data Arpeggio & Digital Stamp (10.5s - 16.5s)
[10.8, 11.1, 11.4, 11.7, 12.0, 12.3].forEach((t, i) => {
  addTone(600 + i * 140, t, 0.06, 0.04, (i % 2 === 0 ? -0.25 : 0.25), 'sine');
});
// Digital stamp thud at 14.5s
addTone(80, 14.5, 0.3, 0.28, 0, 'sub', 0.01, 8.0);
addTone(1050, 14.55, 0.4, 0.18, 0.1, 'warm');

// ── Scene 4: Unit Test 4-Hexagon Sequence & Laser Sweep (16.5s - 21.5s)
[16.8, 17.2, 17.6, 18.0].forEach((t, i) => {
  addTone(523.25 * Math.pow(1.1892, i), t, 0.12, 0.15, -0.3 + i * 0.2, 'warm');
});
// Laser scanner sweep at 19.0s
addSweep(2400, 600, 19.0, 0.5, 0.14, 0, 'sine');

// ── Scene 5: Timetable Snapping & Shutter Click (21.5s - 27.0s)
[22.2, 22.8, 23.4, 24.0].forEach((t, i) => {
  // Snapping click
  addTone(1800, t, 0.025, 0.09, (i % 2 === 0 ? -0.15 : 0.15), 'sine');
});
addTone(1200, 25.2, 0.15, 0.12, 0, 'warm'); // Export print ping

// ── Scene 6: Promotion Flow Riser & Vault Lock Latch (27.0s - 32.5s)
addSweep(300, 950, 27.5, 2.0, 0.12, 0, 'sine'); // Ascension riser
// Metallic latch click at 30.5s
addTone(350, 30.5, 0.08, 0.22, -0.1, 'warm', 0.01, 12.0);
addTone(180, 30.58, 0.15, 0.25, 0.1, 'sub', 0.01, 10.0);

// ── Scene 7: Bonafide Parchment Slide & Gold Seal Stamp (32.5s - 37.5s)
addSweep(150, 450, 32.8, 0.4, 0.08, 0, 'sine');
// Heavy gold wax seal punch at 35.0s
addTone(90, 35.0, 0.35, 0.30, 0, 'sub', 0.01, 7.0);
addTone(784, 35.05, 0.4, 0.16, 0.2, 'warm');
addTone(1568, 36.2, 0.2, 0.12, -0.1, 'sine'); // QR ping

// ── Scene 8: Cloud Drive Upload Swoosh (37.5s - 42.5s)
addSweep(250, 1100, 38.0, 0.8, 0.12, 0, 'sine');
addTone(880, 39.5, 0.3, 0.10, 0.2, 'warm');
addTone(1320, 39.8, 0.4, 0.09, -0.2, 'warm');

// ── Scene 9: Authentic WhatsApp Hub Suite (42.5s - 49.5s)
// 1. WhatsApp Sent Pop at 43.8s (rising tone 400Hz -> 850Hz)
const sentStart = Math.floor(43.8 * sampleRate);
const sentDur = 0.18;
const sentSamples = Math.floor(sentDur * sampleRate);
for (let i = 0; i < sentSamples; i++) {
  const t = i / sampleRate;
  const progress = t / sentDur;
  const freq = 420 + 440 * progress;
  const env = Math.sin(progress * Math.PI) * Math.exp(-progress * 2.0);
  const val = Math.sin(2 * Math.PI * freq * t) * env * 0.32;
  left[sentStart + i] += val;
  right[sentStart + i] += val;
}

// 2. High-speed batch acceleration ticks (44.5s - 46.5s)
for (let i = 0; i < 16; i++) {
  const t = 44.5 + i * 0.12;
  const pitch = 520 + i * 35;
  addTone(pitch, t, 0.045, 0.045 + i * 0.005, (i % 2 === 0 ? -0.25 : 0.25), 'sine');
}

// 3. Authentic WhatsApp Received/Read Two-tone Chime at 46.8s (880Hz -> 1318.5Hz)
const chime1 = Math.floor(46.8 * sampleRate);
const chime2 = Math.floor(46.92 * sampleRate);
for (let i = 0; i < Math.floor(0.22 * sampleRate); i++) {
  const t = i / sampleRate;
  const env = Math.exp(-t * 7.0);
  const val = Math.sin(2 * Math.PI * 880 * t) * env * 0.28;
  left[chime1 + i] += val * 0.9;
  right[chime1 + i] += val * 0.9;
}
for (let i = 0; i < Math.floor(0.42 * sampleRate); i++) {
  const t = i / sampleRate;
  const env = Math.exp(-t * 5.0);
  const val = Math.sin(2 * Math.PI * 1318.5 * t) * env * 0.32;
  left[chime2 + i] += val;
  right[chime2 + i] += val;
}
// Heart tap reaction pop at 47.4s
addTone(1100, 47.4, 0.1, 0.18, 0, 'sine');

// ── Scene 10: Admission CRM & AI Neural Flutter (49.5s - 54.5s)
[49.8, 50.0, 50.2, 50.4, 50.6].forEach((t, i) => {
  addTone(1200 + (i % 3) * 300, t, 0.05, 0.06, (i % 2 === 0 ? -0.2 : 0.2), 'warm');
});
// Staff Approval Lock shield clamp at 52.0s
addTone(260, 52.0, 0.15, 0.22, 0, 'warm', 0.01, 10.0);
addTone(1050, 52.08, 0.25, 0.14, 0.1, 'sine');

// ── Scene 11: Grand Celebratory Finale (54.5s - 60.0s)
const grandBells = [523.25, 659.25, 783.99, 1046.50, 1318.51];
grandBells.forEach((freq, idx) => {
  addTone(freq, 54.8 + idx * 0.09, 2.2, 0.20, (idx % 2 === 0 ? -0.25 : 0.25), 'warm', 0.02, 1.8);
});
// Final CTA button pop at 57.5s
addTone(880, 57.5, 0.4, 0.16, 0, 'warm', 0.01, 2.5);

// ════════════════════════════════════════════════════════════════════════════
// 4. MASTER LIMITER & PEAK NORMALIZATION (-0.5 dBFS)
// ════════════════════════════════════════════════════════════════════════════
let maxPeak = 0;
for (let i = 0; i < totalSamples; i++) {
  const absL = Math.abs(left[i]);
  const absR = Math.abs(right[i]);
  if (absL > maxPeak) maxPeak = absL;
  if (absR > maxPeak) maxPeak = absR;
}

const targetPeak = 0.94; // ~ -0.5 dBFS
const normGain = maxPeak > 0 ? (targetPeak / maxPeak) : 1.0;

// Write 44-byte standard RIFF WAVE header + 16-bit stereo PCM
const wavBuffer = Buffer.alloc(44 + totalSamples * 4);
wavBuffer.write('RIFF', 0);
wavBuffer.writeUInt32LE(36 + totalSamples * 4, 4);
wavBuffer.write('WAVE', 8);
wavBuffer.write('fmt ', 12);
wavBuffer.writeUInt32LE(16, 16);
wavBuffer.writeUInt16LE(1, 20); // PCM
wavBuffer.writeUInt16LE(numChannels, 22); // Stereo
wavBuffer.writeUInt32LE(sampleRate, 24);
wavBuffer.writeUInt32LE(sampleRate * numChannels * 2, 28);
wavBuffer.writeUInt16LE(numChannels * 2, 32);
wavBuffer.writeUInt16LE(16, 34); // 16 bits per sample
wavBuffer.write('data', 36);
wavBuffer.writeUInt32LE(totalSamples * 4, 40);

let offset = 44;
for (let i = 0; i < totalSamples; i++) {
  const sampleL = Math.max(-32768, Math.min(32767, Math.floor(left[i] * normGain * 32767)));
  const sampleR = Math.max(-32768, Math.min(32767, Math.floor(right[i] * normGain * 32767)));
  wavBuffer.writeInt16LE(sampleL, offset);
  wavBuffer.writeInt16LE(sampleR, offset + 2);
  offset += 4;
}

const outPath = path.join(__dirname, 'project-soundtrack.wav');
fs.writeFileSync(outPath, wavBuffer);
console.log('✅ 60-Second Whole Project Soundtrack synthesized successfully:');
console.log('   Path:', outPath);
console.log('   Size:', (wavBuffer.length / 1024 / 1024).toFixed(2), 'MB');
console.log('   Duration: 60.0s @ 44.1kHz Stereo');
