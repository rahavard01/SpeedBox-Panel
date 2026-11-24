package main

import (
 "errors"
 "strings"
 "testing"
)

func TestHTTPFailureHasUsefulFallback(t *testing.T) {
 failure := httpFailure(errors.New("certificate order: acme: could not determine solvers"))
 if failure["stage"] != "certificate order" ||
  !strings.Contains(failure["diagnostic"].(string), "could not determine solvers") {
  t.Fatalf("unexpected diagnostic: %v", failure)
 }
}

func TestHTTPFailureFiltersOpaqueValues(t *testing.T) {
 failure := httpDiagnostic(errors.New("certificate order: rejected https://example.com/acme/order/ABC contact user@example.com token AbCDeFgHiJKLmNoPqRstUvWxYz"))
 for _, secret := range []string{"https://", "user@example.com", "AbCDeFgHiJKLmNoPqRstUvWxYz"} {
  if strings.Contains(failure,secret) {t.Fatalf("diagnostic includes a private value")}
 }
 response := httpDiagnostic(errors.New("403 :: POST :: address :: invalid character in private-response"))
 if !strings.Contains(response,"403 :: POST") || strings.Contains(response,"private-response") {
  t.Fatalf("unexpected response diagnostic: %s",response)
 }
}
