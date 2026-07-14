#!/usr/bin/env node

'use strict';

const fs = require('fs');
const { chromium } = require('playwright');

function fail(message) {
  console.error(String(message || 'Snapshot worker failed.'));
  process.exit(1);
}

function readJob(path) {
  if (!path) {
    fail('Missing job file path.');
  }

  let raw;
  try {
    raw = fs.readFileSync(path, 'utf8');
  } catch (error) {
    fail('Could not read job file.');
  }

  try {
    return JSON.parse(raw);
  } catch (error) {
    fail('Could not parse job file.');
  }
}

function validateJob(job) {
  if (!job || typeof job !== 'object') {
    fail('Invalid job payload.');
  }

  if (typeof job.url !== 'string' || !/^https?:\/\//i.test(job.url)) {
    fail('Invalid job URL.');
  }

  if (typeof job.output !== 'string' || !job.output) {
    fail('Invalid output path.');
  }

  const width = Number(job.viewport && job.viewport.width);
  const height = Number(job.viewport && job.viewport.height);
  const timeoutMs = Number(job.timeoutMs);
  const settleMs = Number(job.settleMs);

  if (!Number.isInteger(width) || width < 320 || width > 7680) {
    fail('Invalid viewport width.');
  }

  if (!Number.isInteger(height) || height < 240 || height > 4320) {
    fail('Invalid viewport height.');
  }

  if (!Number.isInteger(timeoutMs) || timeoutMs < 5000 || timeoutMs > 120000) {
    fail('Invalid timeout.');
  }

  if (!Number.isInteger(settleMs) || settleMs < 0 || settleMs > 30000) {
    fail('Invalid settle delay.');
  }

  if (!Array.isArray(job.allowedHosts) || job.allowedHosts.length === 0) {
    fail('Missing allowed hosts.');
  }

  for (const host of job.allowedHosts) {
    if (typeof host !== 'string' || !/^[a-z0-9][a-z0-9.-]*$/i.test(host)) {
      fail('Invalid allowed host.');
    }
  }
}

function isAllowedRequestUrl(rawUrl, allowedHosts) {
  let parsed;

  try {
    parsed = new URL(rawUrl);
  } catch (error) {
    return false;
  }

  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
    return false;
  }

  if (parsed.username || parsed.password) {
    return false;
  }

  return allowedHosts.includes(parsed.hostname.toLowerCase());
}

async function run() {
  const job = readJob(process.argv[2]);
  validateJob(job);

  let browser;

  try {
    browser = await chromium.launch({
      headless: true
    });

    const context = await browser.newContext({
      viewport: {
        width: job.viewport.width,
        height: job.viewport.height
      },
      deviceScaleFactor: 1,
      acceptDownloads: false
    });

    const page = await context.newPage();
    page.setDefaultTimeout(job.timeoutMs);
    await page.route('**/*', route => {
      if (isAllowedRequestUrl(route.request().url(), job.allowedHosts)) {
        return route.continue();
      }

      return route.abort();
    });
    page.on('popup', async popup => {
      try {
        await popup.close();
      } catch (error) {}
    });

    await page.goto(job.url, {
      waitUntil: 'domcontentloaded',
      timeout: job.timeoutMs
    });

    if (job.settleMs > 0) {
      await page.waitForTimeout(job.settleMs);
    }

    await page.screenshot({
      path: job.output,
      type: 'png',
      fullPage: false
    });

    await context.close();
  } catch (error) {
    fail(error && error.message ? error.message.split('\n')[0] : 'Snapshot render failed.');
  } finally {
    if (browser) {
      try {
        await browser.close();
      } catch (error) {}
    }
  }
}

run();
