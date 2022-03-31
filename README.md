# HTTP Statuscode Checker CLI tool

Small CLI tool to quickly check a list of URLs from a CSV file for its status code

![Screenshots](https://user-images.githubusercontent.com/431360/45353897-808cf280-b5bc-11e8-98c5-7bf2065de76a.png)

## Usage

```shell
Description:
  Run checker on a list of URLs

Usage:
  check [options] [--] [<file>]

Arguments:
  file                             Filename to parse for URLs

Options:
  -u, --url-header=URL-HEADER      Name of header in CSV file for URL [default: "url"]
  -b, --base-uri=BASE-URI          Set the base URI to be prepended for relative URLs
  -a, --user-agent[=USER-AGENT]    Set the user agent to be used for the requests
  -d, --delay=DELAY                Delay between requests [default: 500]
  -f, --file-output[=FILE-OUTPUT]  Write output to CSV file
  -t, --track-redirects            Flag to track intermediate 301/302 status codes in output too
```

#### Built by elgentos
