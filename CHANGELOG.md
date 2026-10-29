# Changelog

## [Unreleased]

### Fixed

- A `ProviderException` no longer carries the client secret and the tokens. The message quoted the
  HTTP client's (the whole URL), the previous was the client's own exception, and every trace of the
  chain kept the arguments of the calls under it (the request's options, the headers and body with
  the credentials) when `zend.exception_ignore_args` is off: a log of the whole chain wrote them.
  The message masks them as `***`, the previous is a copy of the client's exception (the same class
  and code), and no exception `Api` throws keeps its trace's arguments (`Omnifood\Http\Redaction`).
- A 2xx answer whose body is not JSON (a proxy's or a maintenance page) is a `ProviderException`
  (`An answer that is not JSON (HTTP 200).`), where it was read as an empty answer.
  A reservation read so had the date of now and an empty reference.
- The guest's phone is sent in E.164: a national number takes the calling code of the guest's
  locale, else of the `locale` option (`06 12 34 56 78` and `fr_FR`: `+33612345678`, where it
  was `+0612345678`); `0033...` and `+33 (0)6...` are read too; a national number in no country
  known is a `\InvalidArgumentException`, nothing sent.
- A `token_expires_at` PHP cannot read as a date is an `InvalidConfigException` (an `\Exception`
  still), where PHP's own exception escaped the factory.
