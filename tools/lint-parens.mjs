// 粗略 PHP 括号平衡检查：剥离单/双引号字符串与 // 注释后再计数，
// 避免正则/字符串内的括号干扰判断。
import fs from "node:fs";

const file = process.argv[2];
const src = fs.readFileSync(file, "utf8");
let out = "";
let i = 0;
let state = "code"; // code | sq | dq | line | block
while (i < src.length) {
  const c = src[i];
  const n = src[i + 1];
  if (state === "code") {
    if (c === "/" && n === "/") { state = "line"; i += 2; continue; }
    if (c === "/" && n === "*") { state = "block"; i += 2; continue; }
    if (c === "#") { state = "line"; i += 1; continue; }
    if (c === "'") { state = "sq"; i += 1; continue; }
    if (c === '"') { state = "dq"; i += 1; continue; }
    out += c; i += 1; continue;
  }
  if (state === "line") { if (c === "\n") state = "code"; i += 1; continue; }
  if (state === "block") { if (c === "*" && n === "/") { state = "code"; i += 2; continue; } i += 1; continue; }
  if (state === "sq") { if (c === "\\") { i += 2; continue; } if (c === "'") state = "code"; i += 1; continue; }
  if (state === "dq") { if (c === "\\") { i += 2; continue; } if (c === '"') state = "code"; i += 1; continue; }
}
let b = 0, p = 0;
for (const c of out) { if (c === "{") b++; if (c === "}") b--; if (c === "(") p++; if (c === ")") p--; }
console.log(file, "braces:", b, "parens:", p);
