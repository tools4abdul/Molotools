import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(process.cwd());

const pluginTargets = [
  {
    pluginRoot: resolve(root, 'calculators/wealth-tax-calculator/wordpress/wealth-tax-calculator'),
    assets: [
      {
        label: 'wealth tax calculator JS',
        src: 'js/calculator.js',
        out: 'js/calculator.min.js',
        command: 'npx',
        args: [
          '--yes',
          'terser',
          'js/calculator.js',
          '--compress',
          '--mangle',
          '--comments',
          '/^!|@preserve|@license|@cc_on/i',
          '--output',
          'js/calculator.min.js'
        ]
      },
      {
        label: 'wealth tax calculator CSS',
        src: 'css/styles.css',
        out: 'css/styles.min.css',
        command: 'npx',
        args: [
          '--yes',
          'clean-css-cli',
          '-O2',
          '-o',
          'css/styles.min.css',
          'css/styles.css'
        ]
      }
    ]
  },
  {
    pluginRoot: resolve(root, 'calculators/abdulify-me/wordpress/abdulify-me'),
    assets: [
      {
        label: 'abdulify me JS',
        src: 'js/abdulify-me.js',
        out: 'js/abdulify-me.min.js',
        command: 'npx',
        args: [
          '--yes',
          'terser',
          'js/abdulify-me.js',
          '--compress',
          '--mangle',
          '--comments',
          '/^!|@preserve|@license|@cc_on/i',
          '--output',
          'js/abdulify-me.min.js'
        ]
      },
      {
        label: 'abdulify me CSS',
        src: 'css/abdulify-me.css',
        out: 'css/abdulify-me.min.css',
        command: 'npx',
        args: [
          '--yes',
          'clean-css-cli',
          '-O2',
          '-o',
          'css/abdulify-me.min.css',
          'css/abdulify-me.css'
        ]
      }
    ]
  },
  {
    pluginRoot: resolve(root, 'site-translator/wordpress/site-translator'),
    assets: [
      {
        label: 'site translator JS',
        src: 'js/site-translator.js',
        out: 'js/site-translator.min.js',
        command: 'npx',
        args: [
          '--yes',
          'terser',
          'js/site-translator.js',
          '--compress',
          '--mangle',
          '--comments',
          '/^!|@preserve|@license|@cc_on/i',
          '--output',
          'js/site-translator.min.js'
        ]
      },
      {
        label: 'site translator CSS',
        src: 'css/site-translator.css',
        out: 'css/site-translator.min.css',
        command: 'npx',
        args: [
          '--yes',
          'clean-css-cli',
          '-O2',
          '-o',
          'css/site-translator.min.css',
          'css/site-translator.css'
        ]
      }
    ]
  }
];

const targets = pluginTargets.flatMap(function (plugin) {
  return plugin.assets.map(function (asset) {
    return {
      label: asset.label,
      src: resolve(plugin.pluginRoot, asset.src),
      out: resolve(plugin.pluginRoot, asset.out),
      command: asset.command,
      args: asset.args,
      cwd: plugin.pluginRoot
    };
  });
});

const checkMode = process.argv.includes('--check');

function runTarget(target) {
  const before = checkMode ? readFileSync(target.out, 'utf8') : null;

  const result = spawnSync(target.command, target.args, {
    cwd: target.cwd,
    stdio: 'inherit',
    shell: false
  });

  if (result.status !== 0) {
    process.exit(result.status || 1);
  }

  if (!checkMode) {
    return;
  }

  const after = readFileSync(target.out, 'utf8');
  if (before !== after) {
    throw new Error(target.label + ' is out of sync. Run npm run minify and commit the updated minified files.');
  }
}

try {
  for (const target of targets) {
    runTarget(target);
  }
  if (checkMode) {
    process.stdout.write('Minified assets are in sync.\n');
  }
} catch (error) {
  process.stderr.write(String(error.message || error) + '\n');
  process.exit(1);
}
