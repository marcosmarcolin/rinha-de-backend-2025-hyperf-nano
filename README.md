# Rinha de Backend 2025

Este projeto utiliza **PHP 8.3 com Swoole e Hyperf Nano** para entregar alta performance em um ambiente de concorr�ncia.

A solu��o � baseada em:

- **Hyperf Nano**: framework minimalista com suporte a corrotinas.
- **Swoole**: extens�o para PHP com programa��o ass�ncrona e alta performance.
- **Redis**: fila de mensagens e armazenamento intermedi�rio.
- **Docker**: ambiente isolado e reprodut�vel.
- **Nginx**: proxy reverso para balanceamento entre APIs.

## Instru��es de uso

> Para rodar o ambiente completo, basta usar o `make`:

```bash
make up