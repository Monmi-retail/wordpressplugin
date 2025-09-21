const fs = require('fs');
const path = require('path');
const semver = require('semver');
const archiver = require('archiver');

const ROOT = process.cwd();
const PACKAGE_JSON = path.join(ROOT, 'package.json');
const PHP_ENTRY = path.join(ROOT, 'monmi-pay.php');
const DIST_DIR = path.join(ROOT, 'dist');

function bumpVersion() {
  const pkg = JSON.parse(fs.readFileSync(PACKAGE_JSON, 'utf8'));
  const current = pkg.version || '0.0.0';
  const next = semver.inc(current, 'patch');
  pkg.version = next;
  fs.writeFileSync(PACKAGE_JSON, JSON.stringify(pkg, null, 2) + '\n');
  return next;
}
function updatePhpVersion(version) {
  const contents = fs.readFileSync(PHP_ENTRY, 'utf8');
  const updated = contents
    .replace(/(\* Version:\s*)([0-9]+\.[0-9]+\.[0-9]+)/, `$1${version}`)
    .replace(/(const\s+VERSION\s*=\s*')[0-9]+\.[0-9]+\.[0-9]+(')/, `$1${version}$2`);
  fs.writeFileSync(PHP_ENTRY, updated);
}

async function buildZip(version) {
  await fs.promises.mkdir(DIST_DIR, { recursive: true });
  const outputPath = path.join(DIST_DIR, `monmi-pay-${version}.zip`);
  return new Promise((resolve, reject) => {
    const output = fs.createWriteStream(outputPath);
    const archive = archiver('zip', { zlib: { level: 9 } });

    output.on('close', () => resolve(outputPath));
    archive.on('warning', (err) => {
      if (err.code === 'ENOENT') {
        console.warn(err.message);
      } else {
        reject(err);
      }
    });
    archive.on('error', reject);

    archive.pipe(output);

    const include = [
      'monmi-pay.php',
      'includes/**/*',
      'js/**/*',
      'readme.txt',
      'README.md',
      'LICENSE'
    ];

    const ignore = [
      'node_modules/**',
      'dist/**',
      'scripts/**',
      'package-lock.json',
      'package.json',
      '.git/**',
      '.gitignore',
      '.npmrc',
      '*.zip'
    ];

    include.forEach((pattern) => {
      archive.glob(pattern, {
        cwd: ROOT,
        dot: false,
        nodir: false,
        ignore
      });
    });

    archive.finalize();
  });
}

(async () => {
  const version = bumpVersion();
  updatePhpVersion(version);
  const zipPath = await buildZip(version);
  const stats = await fs.promises.stat(zipPath);
  console.log(`Created ${zipPath} (${(stats.size / 1024).toFixed(2)} kB)`);
})();
