// PostToolUse (Edit|Write): roda `php -l` com PHP 8.1 (versão do CI) no .php editado
// e avisa sobre depuração esquecida. Inspirado em rules/php/hooks.md do ECC.
// Saída 2 devolve a mensagem ao Claude; sem PHP instalado, não faz nada.
const fs = require('fs');
const { execFileSync } = require('child_process');

const PHP = 'C:/wamp64/bin/php/php8.1.33/php.exe';

let input = '';
process.stdin.on('data', (chunk) => (input += chunk));
process.stdin.on('end', () => {
  let file;
  try {
    file = JSON.parse(input).tool_input?.file_path;
  } catch {
    process.exit(0);
  }
  if (!file || !file.endsWith('.php') || !fs.existsSync(file) || !fs.existsSync(PHP)) process.exit(0);
  if (/[\\/](scratch|vendor)[\\/]/.test(file)) process.exit(0);

  const problems = [];
  try {
    execFileSync(PHP, ['-l', file], { stdio: 'pipe' });
  } catch (err) {
    problems.push(`php -l (8.1) falhou:\n${(err.stdout || '').toString().trim()}`);
  }

  const debug = fs.readFileSync(file, 'utf8').split('\n')
    .map((line, i) => ({ line, n: i + 1 }))
    .filter(({ line }) => /\b(var_dump|print_r)\s*\(/.test(line) && !/^\s*(\/\/|\*|#)/.test(line));
  if (debug.length) {
    problems.push('Depuração esquecida: ' + debug.map(({ n }) => `linha ${n}`).join(', '));
  }

  if (problems.length) {
    process.stderr.write(`${file}\n${problems.join('\n')}\n`);
    process.exit(2);
  }
});
