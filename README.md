# Neuron Coding Tools

Reusable tools for coding agents built on Neuron AI 4, extracted from Solaro
and ported from pi coding agent 0.70.6.

Requires PHP 8.4.1 or later and the `intl`, `mbstring` and `posix` extensions.
The current implementation targets Unix-like systems. ripgrep is optional.

## Use

Register the toolkit with a Neuron AI Agent:

```php
use NeuronAI\Agent\Agent;
use NeuronCodingTools\CodingToolkit;

$agent = new Agent();
$agent->setThreadId('my-coding-session');
$agent->addTool(new CodingToolkit('/absolute/path/to/project'));
```

The tools are registered in this order:

| Tool | Purpose |
| --- | --- |
| `read` | Read text with optional line offset and limit; output capped at 2,000 lines or 50 KB. Binary files, including images, are described rather than returned. |
| `bash` | Execute a command with an optional timeout; return the last 2,000 lines or 50 KB and save longer output to a temporary file. |
| `edit` | Apply unique, non-overlapping replacements against the original file in one call; preserve BOM and LF/CRLF style. |
| `write` | Create or overwrite a file, creating missing parent directories. |
| `ls` | List a directory, including dotfiles; default limit of 500 entries and an output cap of 50 KB. |
| `find` | Find files by glob; default limit of 1,000 results and an output cap of 50 KB. |
| `grep` | Search a file or directory, with regex or literal text, glob filtering, case options and context; default limit of 100 matching lines and an output cap of 50 KB. |

Every tool can also be instantiated separately from the `NeuronCodingTools\Tool`
namespace. The toolkit supports Neuron's `only()`, `exclude()` and `with()`.

Relative paths resolve from the working directory; absolute paths and `~` are
accepted. The working directory is not an access boundary. Tool failures throw
exceptions; configure your Agent's `toolErrorHandler()` to return their messages
to the model if you want it to recover from failures.

`edit` first tries exact matching, then a normalized Unicode and whitespace form.
If fuzzy matching is needed, that normalization applies to the whole file.
Concurrent writes to the same file are not serialized by this library.
`read` limits the response but still loads the whole file into memory.

## File search

The toolkit selects ripgrep when `rg` is on `PATH`, otherwise pure PHP. To select
an implementation explicitly:

```php
use NeuronCodingTools\CodingToolkit;
use NeuronCodingTools\FileSearch\PhpFileSearch;

$toolkit = new CodingToolkit('/absolute/path/to/project', new PhpFileSearch());
```

Both implementations include hidden files and exclude `.git`. ripgrep honors
nested `.gitignore` files; the PHP fallback reads only the root `.gitignore`,
skips symlinks and always excludes `vendor` and `node_modules`. Under ripgrep
those directories are excluded when the ignore rules list them. Regex dialects
also differ: ripgrep uses its native regex engine, the PHP fallback uses PCRE.

## Local development

Until the package is published, consumers can use a Composer path repository:

```json
{
    "require": {
        "asterixcapri/neuron-coding-tools": "dev-main"
    },
    "repositories": [
        {
            "type": "path",
            "url": "../neuron-coding-tools",
            "options": { "symlink": true }
        }
    ]
}
```

Run `composer install`, then `composer cs:fix`, `composer stan` and `composer test`.
Run `composer cs` before publishing changes. Tests cover both search implementations
when ripgrep is installed; its tests are skipped when it is unavailable.

## License

MIT. See [LICENSE](LICENSE).
