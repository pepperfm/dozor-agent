package protocol

import (
 "encoding/hex"
 "github.com/zeebo/xxh3"
)
// TokenHash matches PHP substr(hash('xxh128', $token), 0, 7).
// It is only a legacy local transport guard, not public authentication.
func TokenHash(token string)string{
 sum:=xxh3.HashString128(token).Bytes()
 return hex.EncodeToString(sum[:])[:7]
}
