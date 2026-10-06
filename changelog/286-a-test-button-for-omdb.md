# A test button for OMDb

Follow-up to #288, found while answering the owner's question about whether the
key reaches the request.

Every credential the Integrations page collects has a **Test key** button —
except OMDb, which shipped without one because `CredentialTester::canTest()` was
never told about it. So a pasted key could only be confirmed by waiting for
enrichment to run and reading the ratings afterwards, which is the slowest
possible feedback for the most common mistake.

## The trap, caught by testing against the live API

OMDb returns **HTTP 401 with the explanation in the body**:

```
status: 401
body:   {"Response":"False","Error":"Invalid API key!"}
```

The first version checked `successful()` first, so it took the 401 branch,
passed `null` as the reason, and printed *"The service rejected that key"* —
discarding the one sentence worth showing. "Invalid API key!" tells somebody
what to fix; the generic line does not.

The body is now read before the status. Verified against the live API both ways:
a bad key reports OMDb's own words, and a 200 carrying `Response: "False"` — how
OMDb answers a bad *id* — is still not read as success.

## Verified

- **43 tests pass** in the integrations suite, 4 new.
- The ordering fix was checked by restoring the status-first version: the
  own-words test fails against it.
- Exercised against the **live OMDb API**, not only fakes.
