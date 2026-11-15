// SirenTicket.sol 컴파일 → build/SirenTicket.json (abi, bytecode)
// 실행: docker run --rm -v "<app>/blockchain:/work" -w /work node:22-alpine sh -c "npm i --no-save solc@0.8.24 && node compile.mjs"
// evmVersion 은 london (PUSH0 없이) — 카이아 등 EVM 호환 체인에서 넓게 돌도록.
import fs from 'node:fs';
import solc from 'solc';

const source = fs.readFileSync('contracts/SirenTicket.sol', 'utf8');
const input = {
    language: 'Solidity',
    sources: { 'SirenTicket.sol': { content: source } },
    settings: {
        optimizer: { enabled: true, runs: 200 },
        evmVersion: 'london',
        outputSelection: { '*': { '*': ['abi', 'evm.bytecode.object'] } },
    },
};
const out = JSON.parse(solc.compile(JSON.stringify(input)));
const errors = (out.errors || []).filter((e) => e.severity === 'error');
if (errors.length) {
    console.error(errors.map((e) => e.formattedMessage).join('\n'));
    process.exit(1);
}
const c = out.contracts['SirenTicket.sol'].SirenTicket;
fs.mkdirSync('build', { recursive: true });
fs.writeFileSync('build/SirenTicket.json', JSON.stringify({
    contractName: 'SirenTicket', compiler: solc.version(), evmVersion: 'london',
    abi: c.abi, bytecode: '0x' + c.evm.bytecode.object,
}, null, 2));
console.log('ok', solc.version(), (c.evm.bytecode.object.length / 2) + ' bytes');
