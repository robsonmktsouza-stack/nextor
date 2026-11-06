const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

async function attempt(responses) {
  const nodes = new Map();
  const node = id => {
    if (!nodes.has(id)) nodes.set(id, {
      value: '', checked: false, hidden: false, disabled: false, textContent: '',
      dataset: {}, handlers: {}, classList: {toggle() {}},
      addEventListener(name, fn) { this.handlers[name] = fn; },
      setAttribute() {}, focus() {}, querySelector: () => node('spinner')
    });
    return nodes.get(id);
  };
  node('lumeronLoginForm').dataset = {portal: '1', baseDomain: 'lumeron.com.br'};
  node('loginSubdomain').value = 'empresaexemplo';
  node('loginUsername').value = 'operador';
  node('loginPassword').value = 'SenhaDeTeste';
  const locations = [];
  const context = vm.createContext({
    document: {getElementById: node}, URL,
    window: {
      localStorage: {getItem: () => null, setItem() {}, removeItem() {}},
      location: {assign: value => locations.push(value)}
    },
    fetch: async () => {
      const result = responses.shift();
      if (result instanceof Error) throw result;
      return result;
    }
  });
  for (const file of ['user-messages.js', 'login.js']) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../public/js', file), 'utf8'), context);
  }
  await node('lumeronLoginForm').handlers.submit({preventDefault() {}});
  return {message: node('loginFeedback').textContent, password: node('loginPassword').value,
    busy: node('loginSubmit').disabled, locations};
}

const bootstrap = () => ({ok: true, json: async () => ({csrf_token: 'token-de-teste'})});

test('login does not display native JSON parser errors', async () => {
  const result = await attempt([{ok: true, json: async () => {
    throw new SyntaxError('Unexpected non-whitespace character after JSON at position 1');
  }}]);
  assert.equal(result.message, 'Não foi possível entrar. Confira a empresa e a conexão, e tente novamente.');
  assert.equal(result.busy, false);
  assert.deepEqual(result.locations, []);
});

test('login does not display technical API validation payloads', async () => {
  const result = await attempt([bootstrap(), {ok: false, status: 422,
    json: async () => ({errors: {username: ['SQLSTATE[42S02]: table users at /var/www/app.php']}})}]);
  assert.equal(result.message, 'Não foi possível entrar. Confira a empresa, o usuário e a senha.');
  assert.deepEqual(result.locations, []);
});

test('login preserves useful credential validation messages', async () => {
  const result = await attempt([bootstrap(), {ok: false, status: 422,
    json: async () => ({errors: {username: ['Usuário ou senha inválidos.']}})}]);
  assert.equal(result.message, 'Usuário ou senha inválidos.');
  assert.equal(result.busy, false);
});

test('login does not display network implementation errors', async () => {
  const result = await attempt([new TypeError('Failed to fetch')]);
  assert.equal(result.message, 'Não foi possível entrar. Confira a empresa e a conexão, e tente novamente.');
  assert.equal(result.busy, false);
});

test('a successful login still navigates to the chosen company and clears the password', async () => {
  const result = await attempt([bootstrap(), {ok: true,
    json: async () => ({redirect: '/dashboard'})}]);
  assert.deepEqual(result.locations, ['https://empresaexemplo.lumeron.com.br/dashboard']);
  assert.equal(result.password, '');
  assert.equal(result.message, '');
});
