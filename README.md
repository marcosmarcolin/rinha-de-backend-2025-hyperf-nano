# Rinha de Backend 2025

Este projeto utiliza **PHP 8.3 com Swoole e Hyperf Nano** para entregar alta performance em um ambiente de concorrência.

A solução é baseada em:

- **Hyperf Nano**: framework minimalista com suporte a corrotinas.
- **Swoole**: extensão para PHP com programação assíncrona e alta performance.
- **Redis**: fila de mensagens e armazenamento intermediário.
- **Docker**: ambiente isolado e reprodutível.
- **Nginx**: proxy reverso para balanceamento entre APIs.

## Instruções de uso

> Para rodar o ambiente completo, basta usar o `make`:

```bash
make up