const fs = require('fs');
const path = require('path');
const archiver = require('archiver');

const ROOT = process.cwd();
const DIST_DIR = path.join(ROOT, 'dist');
const OUTPUT_NAME = 'monmi-pay.zip';
const OUTPUT_PATH = path.join(DIST_DIR, OUTPUT_NAME);

async function ensureDist() {
    await fs.promises.mkdir(DIST_DIR, { recursive: true });
}

async function createArchive() {
    await ensureDist();

    await new Promise((resolve, reject) => {
        const output = fs.createWriteStream(OUTPUT_PATH);
        const archive = archiver('zip', { zlib: { level: 9 } });

        output.on('close', resolve);
        output.on('end', resolve);
        archive.on('warning', (err) => {
            if (err.code === 'ENOENT') {
                console.warn(err.message);
            } else {
                reject(err);
            }
        });
        archive.on('error', reject);

        archive.pipe(output);

        const includeGlobs = [
            'monmi-pay.php',
            'includes/**/*',
            'js/**/*',
            'readme.txt',
            'README.md',
            'LICENSE'
        ];

        const ignoreGlobs = [
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

        includeGlobs.forEach((pattern) => {
            archive.glob(pattern, {
                cwd: ROOT,
                dot: false,
                nodir: false,
                ignore: ignoreGlobs
            });
        });

        archive.finalize().catch(reject);
    });

    const stats = await fs.promises.stat(OUTPUT_PATH);
    console.log(`Created ${OUTPUT_PATH} (${(stats.size / 1024).toFixed(2)} kB)`);
}

createArchive().catch((error) => {
    console.error('Failed to build ZIP:', error);
    process.exit(1);
});
