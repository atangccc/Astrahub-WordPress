// 签名算法交叉验证：复刻 Go 端 internal/security/signature.go 的 Sign()，
// 用固定输入产出预期签名，作为 PHP 实现 WP_AstraHub_Hub_Signer 的对齐基准。
//
// Go:
//   canonical = ToUpper(method) + "\n" + path + "\n" + timestamp + "\n" + nonce + "\n" + bodyHash
//   bodyHash  = sha256_hex(body)
//   signature = lowercase(hex(HMAC-SHA256(secret, canonical)))
//
// PHP 等价：
//   hash('sha256', $body)                      === bodyHash
//   hash_hmac('sha256', $canonical, $secret)   === signature

import crypto from "node:crypto";

function sha256Hex(input) {
  return crypto.createHash("sha256").update(input ?? "", "utf8").digest("hex");
}

function hmacSha256Hex(secret, message) {
  return crypto.createHmac("sha256", secret).update(message, "utf8").digest("hex");
}

function sign(method, path, body, timestamp, nonce, secret) {
  const bodyHash = sha256Hex(body ?? "");
  const canonical = [method.toUpperCase(), path, timestamp, nonce, bodyHash].join("\n");
  return {
    bodyHash,
    canonical,
    signature: hmacSha256Hex(secret, canonical),
  };
}

// 固定测试向量（GET，无 body）。
const v1 = sign("GET", "/v1/planet/links", "", "1700000000", "abc123nonce", "test-api-key");
// 固定测试向量（POST，带 JSON body）。
const v2 = sign(
  "POST",
  "/v1/graph/push",
  '{"siteId":"S1","nodes":[]}',
  "1700000000",
  "def456nonce",
  "test-api-key"
);
// 空 body 的 sha256 固定值校验。
const emptySha = sha256Hex("");

console.log("empty-body-sha256:", emptySha);
console.log(
  "expect empty-body-sha256 == e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855 ->",
  emptySha === "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
);
console.log("");
console.log("[V1 GET /v1/planet/links]");
console.log("  bodyHash :", v1.bodyHash);
console.log("  canonical:", JSON.stringify(v1.canonical));
console.log("  signature:", v1.signature);
console.log("");
console.log("[V2 POST /v1/graph/push]");
console.log("  bodyHash :", v2.bodyHash);
console.log("  canonical:", JSON.stringify(v2.canonical));
console.log("  signature:", v2.signature);
console.log("");
console.log("PHP self-test baselines (paste into class-wp-astrahub-plugin.php):");
console.log("  V1 expected:", v1.signature);
console.log("  V2 expected:", v2.signature);
