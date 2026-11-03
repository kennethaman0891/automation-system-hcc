/**
 * HCC WhatsApp Hub — Audio Synthesizer & Soundtrack Generator
 * Synthesizes a high-fidelity 18-second stereo soundtrack with ambient music
 * and exact frame-accurate WhatsApp sound effects:
 * - Ambient electronic chord progression
 * - Authentic WhatsApp message sent pop (400Hz -> 850Hz)
 * - Authentic WhatsApp incoming/read chime (880Hz -> 1318Hz)
 * - Mouse clicks, typing blips, batch sequential dispatch riser, and celebration bell
 */

const fs = require('fs');
const path = require('path');

const sampleRate = 44100;
const duration = 18.0;
const totalSamples = Math.floor(sampleRate * duration);
const numChannels = 2; // Stereo

// Audio buffers for Left & Right channels
const left = new Float32Array(totalSamples);
const right = new Float32Array(totalSamples);

// Helper: Add sine wave with ADSR envelope
function addTone(freq, startSec, durSec, gain, pan = 0, type = 'sine') {
  const startSample = Math.floor(startSec * sampleRate);
  const endSample = Math.min(totalSamples, startSample + Math.floor(durSec * sampleRate));
  const panL = Math.cos((pan + 1) * Math.PI / 4);
  const panR = Math.sin((pan + 1) * Math.PI / 4);

  for (let i = startSample; i < endSample; i++) {
    const t = (i - startSample) / sampleRate;
    const progress = t / durSec;

    // Smooth envelope: 10% attack, 90% exponential decay
    let env = 1.0;
    if (progress < 0.08) {
      env = progress / 0.08;
    } else {
      env = Math.exp(-progress * 3.5);
    }

    let val = 0;
    if (type === 'sine') {
      val = Math.sin(2 * Math.PI * freq * t);
    } else if (type === 'warm') {
      val = 0.7 * Math.sin(2 * Math.PI * freq * t) + 0.3 * Math.sin(4 * Math.PI * freq * t);
    }

    const sample = val * env * gain;
    left[i] += sample * panL;
    right[i] += sample * panR;
  }
}

// 1. Ambient Synth Pad Chords (Warm background harmony)
// Chords: Am -> F -> C -> G
const chords = [
  { start: 0.0, dur: 3.5, notes: [220, 261.63, 329.63, 392.00] }, // Am7
  { start: 3.5, dur: 3.7, notes: [174.61, 220, 261.63, 329.63] }, // Fmaj7
  { start: 7.0, dur: 4.2, notes: [130.81, 196.00, 261.63, 329.63] }, // C
  { start: 11.2, dur: 3.8, notes: [196.00, 246.94, 293.66, 392.00] }, // G
  { start: 15.0, dur: 3.0, notes: [220, 261.63, 329.63, 523.25] }, // Am (resolving)
];

chords.forEach(c => {
  c.notes.forEach((freq, idx) => {
    const pan = (idx % 2 === 0 ? -0.3 : 0.3);
    addTone(freq, c.start, c.dur, 0.07, pan, 'warm');
    // Sub octave warmth
    addTone(freq * 0.5, c.start, c.dur, 0.04, 0, 'sine');
  });
});

// 2. Subtle rhythmic digital pulses (Tempo: 120 BPM = every 0.5s)
for (let t = 1.0; t < 17.0; t += 0.5) {
  addTone(1200, t, 0.04, 0.015, (t % 1 === 0 ? -0.2 : 0.2), 'sine');
}

// 3. Sound Effects:

// t = 5.1s: UI Click on Aarav Sharma
const clickStart = Math.floor(5.1 * sampleRate);
for (let i = 0; i < Math.floor(0.04 * sampleRate); i++) {
  const t = i / sampleRate;
  const env = Math.exp(-t * 120);
  const clickVal = (Math.sin(2 * Math.PI * 2400 * t) + (Math.random() * 2 - 1) * 0.4) * env * 0.15;
  left[clickStart + i] += clickVal;
  right[clickStart + i] += clickVal;
}

// t = 7.5s, 7.7s, 7.9s: WhatsApp Typing Indicator Blips
[7.4, 7.6, 7.8].forEach((t, idx) => {
  addTone(950 + idx * 60, t, 0.06, 0.04, 0.1, 'sine');
});

// t = 8.4s: Authentic WhatsApp Sent Sound (rising tone 400Hz -> 850Hz)
const sentStart = Math.floor(8.4 * sampleRate);
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

// t = 9.9s: Authentic WhatsApp Received/Read Sound (Two-tone: 880Hz -> 1318.5Hz)
const chimeStart1 = Math.floor(9.9 * sampleRate);
const chimeStart2 = Math.floor(10.02 * sampleRate);
// Tone 1: 880Hz
for (let i = 0; i < Math.floor(0.25 * sampleRate); i++) {
  const t = i / sampleRate;
  const env = Math.exp(-t * 7.0);
  const val = Math.sin(2 * Math.PI * 880 * t) * env * 0.28;
  left[chimeStart1 + i] += val * 0.9;
  right[chimeStart1 + i] += val * 0.9;
}
// Tone 2: 1318.5Hz
for (let i = 0; i < Math.floor(0.45 * sampleRate); i++) {
  const t = i / sampleRate;
  const env = Math.exp(-t * 5.0);
  const val = Math.sin(2 * Math.PI * 1318.5 * t) * env * 0.32;
  left[chimeStart2 + i] += val;
  right[chimeStart2 + i] += val;
}

// t = 10.1s: Sweet Reaction Tapback Pop
addTone(1100, 10.15, 0.1, 0.18, 0, 'sine');

// t = 11.8s - 14.0s: Batch Sequential Dispatch Acceleration Riser
for (let i = 0; i < 18; i++) {
  const t = 11.8 + i * 0.12;
  const pitch = 500 + i * 35;
  addTone(pitch, t, 0.05, 0.05 + i * 0.006, (i % 2 === 0 ? -0.3 : 0.3), 'sine');
}

// t = 14.1s: Batch Completion Celebration Chime (C5, E5, G5, C6)
const bellNotes = [523.25, 659.25, 783.99, 1046.50];
bellNotes.forEach((freq, idx) => {
  addTone(freq, 14.1 + idx * 0.06, 1.6, 0.22, (idx % 2 === 0 ? -0.2 : 0.2), 'warm');
});

// t = 15.8s: CTA Pop
addTone(880, 15.8, 0.25, 0.15, 0, 'warm');

// Master Limiter & Normalization
let maxPeak = 0;
for (let i = 0; i < totalSamples; i++) {
  const absL = Math.abs(left[i]);
  const absR = Math.abs(right[i]);
  if (absL > maxPeak) maxPeak = absL;
  if (absR > maxPeak) maxPeak = absR;
}

const normGain = maxPeak > 0 ? (0.88 / maxPeak) : 1.0;

// Write WAV File (44 bytes header + 16-bit stereo PCM)
const wavBuffer = Buffer.alloc(44 + totalSamples * 4);
wavBuffer.write('RIFF', 0);
wavBuffer.writeUInt32LE(36 + totalSamples * 4, 4);
wavBuffer.write('WAVE', 8);
wavBuffer.write('fmt ', 12);
wavBuffer.writeUInt32LE(16, 16);
wavBuffer.writeUInt16LE(1, 20); // PCM format
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

const outPath = path.join(__dirname, 'soundtrack.wav');
fs.writeFileSync(outPath, wavBuffer);
console.log('Synthesized soundtrack successfully written:', outPath, `(${(wavBuffer.length / 1024 / 1024).toFixed(2)} MB)`);
